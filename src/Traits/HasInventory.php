<?php

namespace Aldeebhasan\Inventorix\Traits;

use Aldeebhasan\Inventorix\Inventorix;
use Aldeebhasan\Inventorix\Models\Location;
use Aldeebhasan\Inventorix\Models\Movement;
use Aldeebhasan\Inventorix\Models\Reservation;
use Aldeebhasan\Inventorix\Models\Stock;
use Aldeebhasan\Inventorix\Models\Threshold;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

trait HasInventory
{
    public function stocks(): HasMany
    {
        return $this->hasMany(Stock::class, 'stockable_id')
            ->where('stockable_type', static::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(Movement::class, 'stockable_id')
            ->where('stockable_type', static::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class, 'stockable_id')
            ->where('stockable_type', static::class);
    }

    public function addStock(int $quantity, Location|int $location, array $options = []): Stock
    {
        return app(Inventorix::class)->addStock($this, $quantity, $location, $options);
    }

    public function deductStock(int $quantity, Location|int $location, array $options = []): Stock
    {
        return app(Inventorix::class)->deductStock($this, $quantity, $location, $options);
    }

    public function transferStock(int $quantity, Location|int $from, Location|int $to, array $options = []): bool
    {
        return app(Inventorix::class)->transfer($this, $quantity, $from, $to, $options);
    }

    public function adjustStock(int $newQuantity, Location|int $location, array $options = []): Stock
    {
        return app(Inventorix::class)->adjustStock($this, $newQuantity, $location, $options);
    }

    public function reserve(int $quantity, Location|int $location, ?Model $reference = null, array $options = []): Reservation
    {
        return app(Inventorix::class)->reserve($this, $quantity, $location, $reference, $options);
    }

    public function releaseReservation(Reservation|int $reservation): bool
    {
        return app(Inventorix::class)->releaseReservation($reservation);
    }

    public function fulfillReservation(Reservation|int $reservation): Stock
    {
        return app(Inventorix::class)->fulfillReservation($reservation);
    }

    public function stockAt(Location|int $location): ?Stock
    {
        $locationId = $location instanceof Location ? $location->id : $location;

        return Stock::where('stockable_type', static::class)
            ->where('stockable_id', $this->getKey())
            ->where('location_id', $locationId)
            ->first();
    }

    public function totalStock(): int
    {
        return (int) Stock::where('stockable_type', static::class)
            ->where('stockable_id', $this->getKey())
            ->sum('quantity');
    }

    public function availableStock(Location|int|null $location = null): int
    {
        $query = Stock::where('stockable_type', static::class)
            ->where('stockable_id', $this->getKey());

        if ($location !== null) {
            $locationId = $location instanceof Location ? $location->id : $location;
            $query->where('location_id', $locationId);
        }

        return (int) $query->get()->sum(fn ($stock) => max(0, $stock->quantity - $stock->reserved_quantity));
    }

    public function reservedStock(Location|int|null $location = null): int
    {
        $query = Stock::where('stockable_type', static::class)
            ->where('stockable_id', $this->getKey());

        if ($location !== null) {
            $locationId = $location instanceof Location ? $location->id : $location;
            $query->where('location_id', $locationId);
        }

        return (int) $query->sum('reserved_quantity');
    }

    public function isLowStock(Location|int|null $location = null): bool
    {
        $query = Stock::where('stockable_type', static::class)
            ->where('stockable_id', $this->getKey());

        if ($location !== null) {
            $locationId = $location instanceof Location ? $location->id : $location;
            $query->where('location_id', $locationId);
        }

        $stocks = $query->get();

        foreach ($stocks as $stock) {
            $threshold = Threshold::where('stockable_type', static::class)
                ->where('stockable_id', $this->getKey())
                ->where(function ($q) use ($stock) {
                    $q->where('location_id', $stock->location_id)
                        ->orWhereNull('location_id');
                })
                ->orderByRaw('location_id IS NULL')
                ->first();

            if ($threshold && $stock->quantity <= $threshold->min_quantity) {
                return true;
            }
        }

        return false;
    }

    public function stockSummary(): array
    {
        $stocks = Stock::where('stockable_type', static::class)
            ->where('stockable_id', $this->getKey())
            ->with('location')
            ->get();

        $totalQuantity = 0;
        $totalReserved = 0;
        $locations = [];

        foreach ($stocks as $stock) {
            $totalQuantity += $stock->quantity;
            $totalReserved += $stock->reserved_quantity;
            $locations[] = [
                'location_id' => $stock->location_id,
                'name' => $stock->location?->name ?? '',
                'quantity' => $stock->quantity,
                'reserved' => $stock->reserved_quantity,
                'available' => max(0, $stock->quantity - $stock->reserved_quantity),
            ];
        }

        $lastMovement = Movement::where('stockable_type', static::class)
            ->where('stockable_id', $this->getKey())
            ->latest()
            ->first();

        return [
            'total_quantity' => $totalQuantity,
            'reserved_quantity' => $totalReserved,
            'available_quantity' => max(0, $totalQuantity - $totalReserved),
            'locations' => $locations,
            'is_low_stock' => $this->isLowStock(),
            'last_movement_at' => $lastMovement?->created_at,
        ];
    }

    public function stockValuation(Location|int|null $location = null, string $costAttribute = 'cost_price'): float
    {
        $costPrice = (float) ($this->{$costAttribute} ?? 0);

        $query = Stock::where('stockable_type', static::class)
            ->where('stockable_id', $this->getKey());

        if ($location !== null) {
            $locationId = $location instanceof Location ? $location->id : $location;
            $query->where('location_id', $locationId);
        }

        $totalQuantity = (int) $query->sum('quantity');

        return $totalQuantity * $costPrice;
    }

    public function setStockThreshold(int $min, ?int $max = null, Location|int|null $location = null): void
    {
        $locationId = null;
        if ($location !== null) {
            $locationId = $location instanceof Location ? $location->id : $location;
        }

        Threshold::updateOrCreate(
            [
                'stockable_type' => static::class,
                'stockable_id' => $this->getKey(),
                'location_id' => $locationId,
            ],
            [
                'min_quantity' => $min,
                'max_quantity' => $max,
            ]
        );
    }

    public function minStock(Location|int|null $location = null): int
    {
        $locationId = null;
        if ($location !== null) {
            $locationId = $location instanceof Location ? $location->id : $location;
        }

        $threshold = Threshold::where('stockable_type', static::class)
            ->where('stockable_id', $this->getKey())
            ->where(function ($q) use ($locationId) {
                if ($locationId !== null) {
                    $q->where('location_id', $locationId)->orWhereNull('location_id');
                } else {
                    $q->whereNull('location_id');
                }
            })
            ->orderByRaw('location_id IS NULL')
            ->first();

        return $threshold?->min_quantity ?? 0;
    }

    public function maxStock(Location|int|null $location = null): ?int
    {
        $locationId = null;
        if ($location !== null) {
            $locationId = $location instanceof Location ? $location->id : $location;
        }

        $threshold = Threshold::where('stockable_type', static::class)
            ->where('stockable_id', $this->getKey())
            ->where(function ($q) use ($locationId) {
                if ($locationId !== null) {
                    $q->where('location_id', $locationId)->orWhereNull('location_id');
                } else {
                    $q->whereNull('location_id');
                }
            })
            ->orderByRaw('location_id IS NULL')
            ->first();

        return $threshold?->max_quantity;
    }

    public function checkThresholds(Location|int|null $location = null): void
    {
        app(Inventorix::class)->checkThresholds($this, $location);
    }

    public function movementHistory(Location|int|null $location = null, string|array|null $type = null, mixed $from = null, mixed $to = null): Builder
    {
        $filters = [];

        if ($location !== null) {
            $filters['location'] = $location;
        }

        if ($type !== null) {
            $filters['type'] = $type;
        }

        if ($from !== null) {
            $filters['from'] = $from;
        }

        if ($to !== null) {
            $filters['to'] = $to;
        }

        return app(Inventorix::class)->movementsFor($this, $filters);
    }
}
