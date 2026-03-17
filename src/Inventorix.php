<?php

namespace Aldeebhasan\Inventorix;

use Aldeebhasan\Inventorix\Enums\MovementType;
use Aldeebhasan\Inventorix\Enums\ReservationStatus;
use Aldeebhasan\Inventorix\Enums\TransactionStatus;
use Aldeebhasan\Inventorix\Enums\TransactionType;
use Aldeebhasan\Inventorix\Events\LowStockReached;
use Aldeebhasan\Inventorix\Events\OverstockReached;
use Aldeebhasan\Inventorix\Events\ReservationFulfilled;
use Aldeebhasan\Inventorix\Events\ReservationReleased;
use Aldeebhasan\Inventorix\Events\StockAdded;
use Aldeebhasan\Inventorix\Events\StockAdjusted;
use Aldeebhasan\Inventorix\Events\StockDeducted;
use Aldeebhasan\Inventorix\Events\StockReserved;
use Aldeebhasan\Inventorix\Events\StockTransferred;
use Aldeebhasan\Inventorix\Exceptions\InsufficientStockException;
use Aldeebhasan\Inventorix\Exceptions\InvalidQuantityException;
use Aldeebhasan\Inventorix\Exceptions\LocationNotFoundException;
use Aldeebhasan\Inventorix\Exceptions\ReservationAlreadyFulfilledException;
use Aldeebhasan\Inventorix\Exceptions\ReservationNotFoundException;
use Aldeebhasan\Inventorix\Models\Location;
use Aldeebhasan\Inventorix\Models\Movement;
use Aldeebhasan\Inventorix\Models\Reservation;
use Aldeebhasan\Inventorix\Models\Stock;
use Aldeebhasan\Inventorix\Models\Threshold;
use Aldeebhasan\Inventorix\Models\Transaction;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class Inventorix
{
    public function __construct(private readonly Dispatcher $events) {}

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

    private function shouldDispatch(string $eventShortName): bool
    {
        if (! config('inventorix.events.enabled', true)) {
            return false;
        }

        $disabled = config('inventorix.events.disable', []);

        return ! in_array($eventShortName, $disabled, true);
    }

    private function evaluateThresholds(mixed $stockable, Stock $stock, Location $location): void
    {
        $threshold = Threshold::where('stockable_type', get_class($stockable))
            ->where('stockable_id', $stockable->getKey())
            ->where(function ($q) use ($location) {
                $q->where('location_id', $location->id)
                    ->orWhereNull('location_id');
            })
            ->orderByRaw('location_id IS NULL')
            ->first();

        if (! $threshold) {
            return;
        }

        if ($stock->quantity <= $threshold->min_quantity && $this->shouldDispatch('LowStockReached')) {
            $event = new LowStockReached($stock, $stockable, $threshold->min_quantity, $location);
            $this->events->dispatch($event);
        }

        if ($threshold->max_quantity !== null && $stock->quantity >= $threshold->max_quantity && $this->shouldDispatch('OverstockReached')) {
            $event = new OverstockReached($stock, $stockable, $threshold->max_quantity, $location);
            $this->events->dispatch($event);
        }
    }

    private function findOrCreateStock(mixed $stockable, Location $location): Stock
    {
        return Stock::firstOrCreate(
            [
                'stockable_type' => get_class($stockable),
                'stockable_id' => $stockable->getKey(),
                'location_id' => $location->id,
            ],
            [
                'quantity' => 0,
                'reserved_quantity' => 0,
            ]
        );
    }

    private function recordMovement(array $data): Movement
    {
        return Movement::create($data);
    }

    public function addStock(Model $stockable, int $quantity, Location|int $location, array $options = []): Stock
    {
        if ($quantity <= 0) {
            throw new InvalidQuantityException;
        }

        $location = $this->resolveLocation($location);

        return DB::transaction(function () use ($stockable, $quantity, $location, $options) {
            $stock = $this->findOrCreateStock($stockable, $location);
            $beforeQuantity = $stock->quantity;

            $stock->increment('quantity', $quantity);
            $stock->refresh();

            $movement = $this->recordMovement([
                'stockable_type' => get_class($stockable),
                'stockable_id' => $stockable->getKey(),
                'location_id' => $location->id,
                'transaction_id' => isset($options['transaction']) ? $options['transaction']->id : null,
                'type' => MovementType::Add,
                'quantity' => $quantity,
                'before_quantity' => $beforeQuantity,
                'after_quantity' => $stock->quantity,
                'reference_type' => isset($options['reference']) ? get_class($options['reference']) : null,
                'reference_id' => isset($options['reference']) ? $options['reference']->getKey() : null,
                'note' => $options['note'] ?? null,
                'created_by' => $options['created_by'] ?? null,
            ]);

            if ($this->shouldDispatch('StockAdded')) {
                $this->events->dispatch(new StockAdded($stockable, $stock, $movement, $quantity, $location));
            }

            $this->evaluateThresholds($stockable, $stock, $location);

            return $stock;
        });
    }

    public function deductStock(Model $stockable, int $quantity, Location|int $location, array $options = []): Stock
    {
        if ($quantity <= 0) {
            throw new InvalidQuantityException;
        }

        $location = $this->resolveLocation($location);

        return DB::transaction(function () use ($stockable, $quantity, $location, $options) {
            $stock = $this->findOrCreateStock($stockable, $location);
            $allowNegative = $options['allow_negative'] ?? config('inventorix.allow_negative_stock', false);

            if (! $allowNegative && $stock->available_quantity < $quantity) {
                throw new InsufficientStockException(
                    "Insufficient stock. Available: {$stock->available_quantity}, Requested: {$quantity}."
                );
            }

            $beforeQuantity = $stock->quantity;

            $stock->decrement('quantity', $quantity);
            $stock->refresh();

            $movement = $this->recordMovement([
                'stockable_type' => get_class($stockable),
                'stockable_id' => $stockable->getKey(),
                'location_id' => $location->id,
                'transaction_id' => isset($options['transaction']) ? $options['transaction']->id : null,
                'type' => MovementType::Deduct,
                'quantity' => $quantity,
                'before_quantity' => $beforeQuantity,
                'after_quantity' => $stock->quantity,
                'reference_type' => isset($options['reference']) ? get_class($options['reference']) : null,
                'reference_id' => isset($options['reference']) ? $options['reference']->getKey() : null,
                'note' => $options['note'] ?? null,
                'created_by' => $options['created_by'] ?? null,
            ]);

            if ($this->shouldDispatch('StockDeducted')) {
                $this->events->dispatch(new StockDeducted($stockable, $stock, $movement, $quantity, $location));
            }

            $this->evaluateThresholds($stockable, $stock, $location);

            return $stock;
        });
    }

    public function transfer(Model $stockable, int $quantity, Location|int $from, Location|int $to, array $options = []): bool
    {
        if ($quantity <= 0) {
            throw new InvalidQuantityException;
        }

        $fromLocation = $this->resolveLocation($from);
        $toLocation = $this->resolveLocation($to);

        DB::transaction(function () use ($stockable, $quantity, $fromLocation, $toLocation, $options) {
            $transaction = Transaction::create([
                'type' => TransactionType::Transfer,
                'status' => TransactionStatus::Pending,
                'note' => $options['note'] ?? null,
                'created_by' => $options['created_by'] ?? null,
            ]);

            $fromStock = $this->findOrCreateStock($stockable, $fromLocation);
            $allowNegative = $options['allow_negative'] ?? config('inventorix.allow_negative_stock', false);

            if (! $allowNegative && $fromStock->available_quantity < $quantity) {
                throw new InsufficientStockException(
                    "Insufficient stock at source. Available: {$fromStock->available_quantity}, Requested: {$quantity}."
                );
            }

            $beforeFrom = $fromStock->quantity;
            $fromStock->decrement('quantity', $quantity);
            $fromStock->refresh();

            $this->recordMovement([
                'stockable_type' => get_class($stockable),
                'stockable_id' => $stockable->getKey(),
                'location_id' => $fromLocation->id,
                'transaction_id' => $transaction->id,
                'type' => MovementType::TransferOut,
                'quantity' => $quantity,
                'before_quantity' => $beforeFrom,
                'after_quantity' => $fromStock->quantity,
                'reference_type' => isset($options['reference']) ? get_class($options['reference']) : null,
                'reference_id' => isset($options['reference']) ? $options['reference']->getKey() : null,
                'note' => $options['note'] ?? null,
                'created_by' => $options['created_by'] ?? null,
            ]);

            $toStock = $this->findOrCreateStock($stockable, $toLocation);
            $beforeTo = $toStock->quantity;
            $toStock->increment('quantity', $quantity);
            $toStock->refresh();

            $this->recordMovement([
                'stockable_type' => get_class($stockable),
                'stockable_id' => $stockable->getKey(),
                'location_id' => $toLocation->id,
                'transaction_id' => $transaction->id,
                'type' => MovementType::TransferIn,
                'quantity' => $quantity,
                'before_quantity' => $beforeTo,
                'after_quantity' => $toStock->quantity,
                'reference_type' => isset($options['reference']) ? get_class($options['reference']) : null,
                'reference_id' => isset($options['reference']) ? $options['reference']->getKey() : null,
                'note' => $options['note'] ?? null,
                'created_by' => $options['created_by'] ?? null,
            ]);

            $transaction->update(['status' => TransactionStatus::Committed]);

            if ($this->shouldDispatch('StockTransferred')) {
                $this->events->dispatch(new StockTransferred($stockable, $quantity, $fromLocation, $toLocation, $transaction));
            }
        });

        return true;
    }

    public function adjustStock(Model $stockable, int $newQuantity, Location|int $location, array $options = []): Stock
    {
        $location = $this->resolveLocation($location);

        return DB::transaction(function () use ($stockable, $newQuantity, $location, $options) {
            $stock = $this->findOrCreateStock($stockable, $location);
            $previousQuantity = $stock->quantity;
            $delta = $newQuantity - $previousQuantity;

            $stock->update(['quantity' => $newQuantity]);
            $stock->refresh();

            $movement = $this->recordMovement([
                'stockable_type' => get_class($stockable),
                'stockable_id' => $stockable->getKey(),
                'location_id' => $location->id,
                'transaction_id' => isset($options['transaction']) ? $options['transaction']->id : null,
                'type' => MovementType::Adjustment,
                'quantity' => $delta,
                'before_quantity' => $previousQuantity,
                'after_quantity' => $newQuantity,
                'note' => $options['note'] ?? $options['reason'] ?? null,
                'created_by' => $options['created_by'] ?? null,
            ]);

            if ($this->shouldDispatch('StockAdjusted')) {
                $this->events->dispatch(new StockAdjusted($stockable, $stock, $movement, $previousQuantity, $newQuantity, $location));
            }

            $this->evaluateThresholds($stockable, $stock, $location);

            return $stock;
        });
    }

    public function bulk(callable $callback): Transaction
    {
        return DB::transaction(function () use ($callback) {
            $transaction = Transaction::create([
                'type' => TransactionType::Manual,
                'status' => TransactionStatus::Pending,
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

    public function reserve(Model $stockable, int $quantity, Location|int $location, ?Model $reference = null, array $options = []): Reservation
    {
        if ($quantity <= 0) {
            throw new InvalidQuantityException;
        }

        $location = $this->resolveLocation($location);

        return DB::transaction(function () use ($stockable, $quantity, $location, $reference, $options) {
            $stock = $this->findOrCreateStock($stockable, $location);

            if ($stock->available_quantity < $quantity) {
                throw new InsufficientStockException(
                    "Insufficient available stock. Available: {$stock->available_quantity}, Requested: {$quantity}."
                );
            }

            $stock->increment('reserved_quantity', $quantity);
            $stock->refresh();

            $expiresAt = $options['expires_at'] ?? null;
            if ($expiresAt === null && config('inventorix.reservation_ttl_minutes') !== null) {
                $expiresAt = now()->addMinutes(config('inventorix.reservation_ttl_minutes'));
            }

            $reservation = Reservation::create([
                'stockable_type' => get_class($stockable),
                'stockable_id' => $stockable->getKey(),
                'location_id' => $location->id,
                'quantity' => $quantity,
                'status' => ReservationStatus::Pending,
                'reference_type' => $reference ? get_class($reference) : null,
                'reference_id' => $reference ? $reference->getKey() : null,
                'note' => $options['note'] ?? null,
                'created_by' => $options['created_by'] ?? null,
                'expires_at' => $expiresAt,
            ]);

            $this->recordMovement([
                'stockable_type' => get_class($stockable),
                'stockable_id' => $stockable->getKey(),
                'location_id' => $location->id,
                'type' => MovementType::Reservation,
                'quantity' => $quantity,
                'before_quantity' => $stock->quantity,
                'after_quantity' => $stock->quantity,
                'note' => $options['note'] ?? null,
                'created_by' => $options['created_by'] ?? null,
            ]);

            if ($this->shouldDispatch('StockReserved')) {
                $this->events->dispatch(new StockReserved($stockable, $reservation, $location));
            }

            return $reservation;
        });
    }

    public function releaseReservation(Reservation|int $reservation): bool
    {
        if (is_int($reservation)) {
            $reservation = Reservation::find($reservation);
            if (! $reservation) {
                throw new ReservationNotFoundException;
            }
        }

        if ($reservation->status !== ReservationStatus::Pending) {
            throw new ReservationAlreadyFulfilledException;
        }

        return DB::transaction(function () use ($reservation) {
            $location = $reservation->location;
            $stockable = $reservation->stockable;

            $stock = Stock::where('stockable_type', $reservation->stockable_type)
                ->where('stockable_id', $reservation->stockable_id)
                ->where('location_id', $reservation->location_id)
                ->firstOrFail();

            $stock->decrement('reserved_quantity', $reservation->quantity);
            $stock->refresh();

            $reservation->update(['status' => ReservationStatus::Released]);

            $this->recordMovement([
                'stockable_type' => $reservation->stockable_type,
                'stockable_id' => $reservation->stockable_id,
                'location_id' => $reservation->location_id,
                'type' => MovementType::ReservationRelease,
                'quantity' => $reservation->quantity,
                'before_quantity' => $stock->quantity,
                'after_quantity' => $stock->quantity,
                'note' => $reservation->note,
                'created_by' => $reservation->created_by,
            ]);

            if ($this->shouldDispatch('ReservationReleased')) {
                $this->events->dispatch(new ReservationReleased($reservation, $stockable, $location));
            }

            return true;
        });
    }

    public function fulfillReservation(Reservation|int $reservation): Stock
    {
        if (is_int($reservation)) {
            $reservation = Reservation::find($reservation);
            if (! $reservation) {
                throw new ReservationNotFoundException;
            }
        }

        if ($reservation->status !== ReservationStatus::Pending) {
            throw new ReservationAlreadyFulfilledException;
        }

        return DB::transaction(function () use ($reservation) {
            $location = $reservation->location;
            $stockable = $reservation->stockable;

            $stock = Stock::where('stockable_type', $reservation->stockable_type)
                ->where('stockable_id', $reservation->stockable_id)
                ->where('location_id', $reservation->location_id)
                ->firstOrFail();

            $stock->decrement('quantity', $reservation->quantity);
            $stock->decrement('reserved_quantity', $reservation->quantity);
            $stock->refresh();

            $reservation->update(['status' => ReservationStatus::Fulfilled]);

            $this->recordMovement([
                'stockable_type' => $reservation->stockable_type,
                'stockable_id' => $reservation->stockable_id,
                'location_id' => $reservation->location_id,
                'type' => MovementType::Fulfillment,
                'quantity' => $reservation->quantity,
                'before_quantity' => $stock->quantity + $reservation->quantity,
                'after_quantity' => $stock->quantity,
                'note' => $reservation->note,
                'created_by' => $reservation->created_by,
            ]);

            if ($this->shouldDispatch('ReservationFulfilled')) {
                $this->events->dispatch(new ReservationFulfilled($reservation, $stock, $stockable, $location));
            }

            return $stock;
        });
    }

    public function movementsFor(Model $stockable, array $filters = []): Builder
    {
        $query = Movement::where('stockable_type', get_class($stockable))
            ->where('stockable_id', $stockable->getKey());

        if (isset($filters['location'])) {
            $location = $this->resolveLocation($filters['location']);
            $query->where('location_id', $location->id);
        }

        if (isset($filters['type'])) {
            $type = $filters['type'];
            if (is_array($type)) {
                $query->whereIn('type', $type);
            } else {
                $query->where('type', $type);
            }
        }

        if (isset($filters['from'])) {
            $query->where('created_at', '>=', $filters['from']);
        }

        if (isset($filters['to'])) {
            $query->where('created_at', '<=', $filters['to']);
        }

        return $query;
    }

    public function lowStockItems(Location|int|null $location = null, ?string $stockableType = null): Collection
    {
        $stocksTable = config('inventorix.tables.stocks', 'inventorix_stocks');
        $thresholdsTable = config('inventorix.tables.thresholds', 'inventorix_thresholds');

        $query = Stock::join($thresholdsTable, function ($join) use ($stocksTable, $thresholdsTable) {
            $join->on("{$thresholdsTable}.stockable_type", '=', "{$stocksTable}.stockable_type")
                ->on("{$thresholdsTable}.stockable_id", '=', "{$stocksTable}.stockable_id")
                ->where(function ($q) use ($stocksTable, $thresholdsTable) {
                    $q->whereColumn("{$thresholdsTable}.location_id", "{$stocksTable}.location_id")
                        ->orWhereNull("{$thresholdsTable}.location_id");
                });
        })
            ->whereColumn("{$stocksTable}.quantity", '<=', "{$thresholdsTable}.min_quantity")
            ->select("{$stocksTable}.*");

        if ($location !== null) {
            $location = $this->resolveLocation($location);
            $query->where("{$stocksTable}.location_id", $location->id);
        }

        if ($stockableType !== null) {
            $query->where("{$stocksTable}.stockable_type", $stockableType);
        }

        return $query->get();
    }

    public function totalValuation(Location|int|null $location = null, string $costAttribute = 'cost_price'): float
    {
        $query = Stock::query();

        if ($location !== null) {
            $locationModel = $this->resolveLocation($location);
            $query->where('location_id', $locationModel->id);
        }

        $stocks = $query->get();
        $total = 0.0;

        foreach ($stocks as $stock) {
            $stockable = $stock->stockable;
            if ($stockable && isset($stockable->{$costAttribute})) {
                $total += $stock->quantity * (float) $stockable->{$costAttribute};
            }
        }

        return $total;
    }

    public function checkThresholds(Model $stockable, Location|int|null $location = null): void
    {
        $query = Stock::where('stockable_type', get_class($stockable))
            ->where('stockable_id', $stockable->getKey());

        if ($location !== null) {
            $locationModel = $this->resolveLocation($location);
            $query->where('location_id', $locationModel->id);
        }

        $stocks = $query->get();

        foreach ($stocks as $stock) {
            $this->evaluateThresholds($stockable, $stock, $stock->location);
        }
    }
}
