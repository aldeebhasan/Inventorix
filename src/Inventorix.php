<?php

namespace Aldeebhasan\Inventorix;

use Aldeebhasan\Inventorix\Contracts\ReservationServiceInterface;
use Aldeebhasan\Inventorix\Contracts\RollbackServiceInterface;
use Aldeebhasan\Inventorix\Contracts\StockQueryInterface;
use Aldeebhasan\Inventorix\Contracts\StockServiceInterface;
use Aldeebhasan\Inventorix\Contracts\ThresholdServiceInterface;
use Aldeebhasan\Inventorix\Contracts\TransferServiceInterface;
use Aldeebhasan\Inventorix\Contracts\ValuationServiceInterface;
use Aldeebhasan\Inventorix\DTOs\StockOperationDto;
use Aldeebhasan\Inventorix\Enums\TransactionStatus;
use Aldeebhasan\Inventorix\Enums\TransactionType;
use Aldeebhasan\Inventorix\Exceptions\LocationNotFoundException;
use Aldeebhasan\Inventorix\Models\Location;
use Aldeebhasan\Inventorix\Models\Reservation;
use Aldeebhasan\Inventorix\Models\Stock;
use Aldeebhasan\Inventorix\Models\Transaction;
use Aldeebhasan\Inventorix\Queries\StockVelocityQuery;
use Aldeebhasan\Inventorix\Support\HookRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class Inventorix
{
    public function __construct(
        private readonly StockServiceInterface $stocks,
        private readonly TransferServiceInterface $transfers,
        private readonly ReservationServiceInterface $reservations,
        private readonly ValuationServiceInterface $valuation,
        private readonly ThresholdServiceInterface $thresholds,
        private readonly StockQueryInterface $queries,
        private readonly RollbackServiceInterface $rollbacks,
        private readonly StockVelocityQuery $velocity,
        private readonly HookRegistry $hooks,
    ) {}

    private function resolveLocation(Location|int $location): Location
    {
        if ($location instanceof Location) {
            return $location;
        }

        try {
            $found = Location::findOrFail($location);
        } catch (\Exception) {
            throw new LocationNotFoundException("Location with ID [{$location}] not found.");
        }

        return $found;
    }

    public function addStock(Model $stockable, int|float $quantity, Location|int $location, StockOperationDto $options = new StockOperationDto): Stock
    {
        return $this->stocks->add($stockable, $quantity, $this->resolveLocation($location), $options);
    }

    public function deductStock(Model $stockable, int|float $quantity, Location|int $location, StockOperationDto $options = new StockOperationDto): Stock
    {
        return $this->stocks->deduct($stockable, $quantity, $this->resolveLocation($location), $options);
    }

    public function transfer(Model $stockable, int|float $quantity, Location|int $from, Location|int $to, StockOperationDto $options = new StockOperationDto): bool
    {
        return $this->transfers->transfer($stockable, $quantity, $this->resolveLocation($from), $this->resolveLocation($to), $options);
    }

    public function adjustStock(Model $stockable, int|float $newQuantity, Location|int $location, StockOperationDto $options = new StockOperationDto): Stock
    {
        return $this->stocks->adjust($stockable, $newQuantity, $this->resolveLocation($location), $options);
    }

    public function adjustByReference(
        Model $stockable,
        Model $reference,
        int|float $newQuantity,
        Location|int $location,
        StockOperationDto $options = new StockOperationDto
    ): Stock {
        return $this->stocks->adjustByReference($stockable, $reference, $newQuantity, $this->resolveLocation($location), $options);
    }

    public function bulk(callable $callback, StockOperationDto $options = new StockOperationDto): Transaction
    {
        return DB::transaction(function () use ($callback, $options) {
            $transaction = Transaction::create([
                'type' => $options->transactionType ?? TransactionType::Manual,
                'status' => TransactionStatus::Pending,
                'causable_type' => $options->causable ? get_class($options->causable) : null,
                'causable_id' => $options->causable?->getKey(),
                'note' => $options->note,
                'created_by' => $options->createdBy,
            ]);

            try {
                $callback($transaction);
                $transaction->update(['status' => TransactionStatus::Committed]);
            } catch (\Throwable $e) {
                $transaction->update(['status' => TransactionStatus::RolledBack]);
                throw $e;
            }

            return $transaction;
        });
    }

    public function reserve(Model $stockable, int|float $quantity, Location|int $location, StockOperationDto $options = new StockOperationDto): Reservation
    {
        return $this->reservations->reserve($stockable, $quantity, $this->resolveLocation($location), $options);
    }

    public function releaseReservation(Reservation|int $reservation): bool
    {
        return $this->reservations->release($reservation);
    }

    public function fulfillReservation(Reservation|int $reservation): Stock
    {
        return $this->reservations->fulfill($reservation);
    }

    public function movementsFor(Model $stockable, array $filters = []): Builder
    {
        return $this->queries->movementsFor($stockable, $filters);
    }

    public function lowStockItems(Location|int|null $location = null, ?string $stockableType = null): Collection
    {
        $resolvedLocation = $location !== null ? $this->resolveLocation($location) : null;

        return $this->queries->lowStockItems($resolvedLocation, $stockableType);
    }

    public function totalValuation(Location|int|null $location = null, ?Model $stockable = null, string $costAttribute = 'cost_price'): float
    {
        $resolvedLocation = $location !== null ? $this->resolveLocation($location) : null;

        return $this->valuation->totalValuation($resolvedLocation, $stockable, $costAttribute);
    }

    public function checkThresholds(Model $stockable, Location|int|null $location = null): void
    {
        $resolvedLocation = $location !== null ? $this->resolveLocation($location) : null;

        $this->thresholds->check($stockable, $resolvedLocation);
    }

    public function beforeAdd(callable $hook): void
    {
        $this->hooks->register('beforeAdd', $hook);
    }

    public function afterAdd(callable $hook): void
    {
        $this->hooks->register('afterAdd', $hook);
    }

    public function beforeDeduct(callable $hook): void
    {
        $this->hooks->register('beforeDeduct', $hook);
    }

    public function afterDeduct(callable $hook): void
    {
        $this->hooks->register('afterDeduct', $hook);
    }

    public function rollback(Transaction $transaction, StockOperationDto $options = new StockOperationDto): Transaction
    {
        return $this->rollbacks->rollback($transaction, $options);
    }

    public function movementsByCausable(Model $causable, array $filters = []): Builder
    {
        return $this->queries->movementsByCausable($causable, $filters);
    }

    public function valuationByCausable(Model $causable): float
    {
        return $this->valuation->valuationByCausable($causable);
    }

    public function stockVelocity(Model $stockable, Location|int $location, int $days = 30): float
    {
        return $this->velocity->velocity($stockable, $this->resolveLocation($location), $days);
    }

    public function daysOfStock(Model $stockable, Location|int $location, int $velocityDays = 30): float
    {
        return $this->velocity->daysOfStock($stockable, $this->resolveLocation($location), $velocityDays);
    }

    public function peakDemandDay(Model $stockable, Location|int $location, int $days = 90): ?Carbon
    {
        return $this->velocity->peakDemandDay($stockable, $this->resolveLocation($location), $days);
    }
}
