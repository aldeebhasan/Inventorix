<?php

namespace Aldeebhasan\Inventorix\Traits\Concerns;

use Aldeebhasan\Inventorix\Inventorix;
use Aldeebhasan\Inventorix\Models\Location;
use Aldeebhasan\Inventorix\Models\Threshold;

trait ManagesThresholds
{
    public function setStockThreshold(int|float $min, int|float|null $max = null, Location|int|null $location = null): void
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

    public function minStock(Location|int|null $location = null): float
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

        return (float) ($threshold?->min_quantity ?? 0);
    }

    public function maxStock(Location|int|null $location = null): ?float
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

        return $threshold?->max_quantity !== null ? (float) $threshold->max_quantity : null;
    }

    public function checkThresholds(Location|int|null $location = null): void
    {
        app(Inventorix::class)->checkThresholds($this, $location);
    }
}
