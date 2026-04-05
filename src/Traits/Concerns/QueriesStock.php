<?php

namespace Aldeebhasan\Inventorix\Traits\Concerns;

use Aldeebhasan\Inventorix\Models\Location;
use Aldeebhasan\Inventorix\Models\Movement;
use Aldeebhasan\Inventorix\Models\Stock;
use Aldeebhasan\Inventorix\Models\Threshold;
use Aldeebhasan\Inventorix\Services\ValuationService;

trait QueriesStock
{
    public function stockAt(Location|int $location): ?Stock
    {
        $locationId = $location instanceof Location ? $location->id : $location;

        return Stock::where('stockable_type', static::class)
            ->where('stockable_id', $this->getKey())
            ->where('location_id', $locationId)
            ->first();
    }

    public function totalStock(?Location $location = null, bool $includeChildren = false): float
    {
        $query = Stock::where('stockable_type', static::class)
            ->where('stockable_id', $this->getKey());

        if ($location !== null) {
            if ($includeChildren) {
                $query->atOrBelow($location);
            } else {
                $query->where('location_id', $location->id);
            }
        }

        return (float) $query->sum('quantity');
    }

    public function availableStock(Location|int|null $location = null, bool $includeChildren = false): float
    {
        $query = Stock::where('stockable_type', static::class)
            ->where('stockable_id', $this->getKey());

        if ($location !== null) {
            if ($includeChildren && $location instanceof Location) {
                $query->atOrBelow($location);
            } else {
                $locationId = $location instanceof Location ? $location->id : $location;
                $query->where('location_id', $locationId);
            }
        }

        return (float) $query->get()->sum(fn ($stock) => max(0, $stock->quantity - $stock->reserved_quantity));
    }

    public function reservedStock(Location|int|null $location = null, bool $includeChildren = false): float
    {
        $query = Stock::where('stockable_type', static::class)
            ->where('stockable_id', $this->getKey());

        if ($location !== null) {
            if ($includeChildren && $location instanceof Location) {
                $query->atOrBelow($location);
            } else {
                $locationId = $location instanceof Location ? $location->id : $location;
                $query->where('location_id', $locationId);
            }
        }

        return (float) $query->sum('reserved_quantity');
    }

    public function isLowStock(Location|int|null $location = null, bool $includeChildren = false): bool
    {
        $query = Stock::where('stockable_type', static::class)
            ->where('stockable_id', $this->getKey());

        if ($location !== null) {
            if ($includeChildren && $location instanceof Location) {
                $query->atOrBelow($location);
            } else {
                $locationId = $location instanceof Location ? $location->id : $location;
                $query->where('location_id', $locationId);
            }
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

    public function stockSummary(?Location $location = null, bool $includeChildren = false): array
    {
        $query = Stock::where('stockable_type', static::class)
            ->where('stockable_id', $this->getKey())
            ->with('location');

        if ($location !== null) {
            if ($includeChildren) {
                $query->atOrBelow($location);
            } else {
                $query->where('location_id', $location->id);
            }
        }

        $stocks = $query->get();

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

    public function stockValuation(Location|int|null $location = null): float
    {
        if (is_int($location)) {
            $location = Location::find($location);
        }

        return app(ValuationService::class)->totalValuation(location: $location, stockable: $this);
    }
}
