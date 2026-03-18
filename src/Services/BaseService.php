<?php

namespace Aldeebhasan\Inventorix\Services;

use Aldeebhasan\Inventorix\Models\Location;
use Aldeebhasan\Inventorix\Models\Movement;
use Aldeebhasan\Inventorix\Models\Stock;

abstract class BaseService
{
    protected function shouldDispatch(string $eventShortName): bool
    {
        if (! config('inventorix.events.enabled', true)) {
            return false;
        }

        $disabled = config('inventorix.events.disable', []);

        return ! in_array($eventShortName, $disabled, true);
    }

    protected function findOrCreateStock(mixed $stockable, Location $location): Stock
    {
        $attributes = [
            'stockable_type' => get_class($stockable),
            'stockable_id' => $stockable->getKey(),
            'location_id' => $location->id,
        ];

        $stock = Stock::where($attributes)->lockForUpdate()->first();

        if (! $stock) {
            $stock = Stock::create(array_merge($attributes, [
                'quantity' => 0,
                'reserved_quantity' => 0,
            ]));
        }

        return $stock;
    }

    protected function recordMovement(array $data): Movement
    {
        return Movement::create($data);
    }
}
