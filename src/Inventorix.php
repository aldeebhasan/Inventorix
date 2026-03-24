<?php

namespace Aldeebhasan\Inventorix;

use Aldeebhasan\Inventorix\Contracts\ReservationServiceInterface;
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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
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

    public function totalValuation(Location|int|null $location = null, string $costAttribute = 'cost_price'): float
    {
        $resolvedLocation = $location !== null ? $this->resolveLocation($location) : null;

        return $this->valuation->totalValuation($resolvedLocation, $costAttribute);
    }

    public function checkThresholds(Model $stockable, Location|int|null $location = null): void
    {
        $resolvedLocation = $location !== null ? $this->resolveLocation($location) : null;

        $this->thresholds->check($stockable, $resolvedLocation);
    }
}
