<?php

namespace Aldeebhasan\Inventorix\Services;

use Aldeebhasan\Inventorix\Contracts\ThresholdServiceInterface;
use Aldeebhasan\Inventorix\Events\LowStockReached;
use Aldeebhasan\Inventorix\Events\OverstockReached;
use Aldeebhasan\Inventorix\Models\Location;
use Aldeebhasan\Inventorix\Models\Stock;
use Aldeebhasan\Inventorix\Support\ThresholdCache;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;

class ThresholdService implements ThresholdServiceInterface
{
    public function __construct(private readonly Dispatcher $events) {}

    private function shouldDispatch(string $eventShortName): bool
    {
        if (! config('inventorix.events.enabled', true)) {
            return false;
        }

        $disabled = config('inventorix.events.disable', []);

        return ! in_array($eventShortName, $disabled, true);
    }

    public function evaluate(mixed $stockable, Stock $stock, Location $location): void
    {
        $threshold = ThresholdCache::get(get_class($stockable), $stockable->getKey(), $location->id);

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

    public function check(Model $stockable, ?Location $location = null): void
    {
        $query = Stock::where('stockable_type', get_class($stockable))
            ->where('stockable_id', $stockable->getKey());

        if ($location !== null) {
            $query->where('location_id', $location->id);
        }

        $stocks = $query->get();

        foreach ($stocks as $stock) {
            $this->evaluate($stockable, $stock, $stock->location);
        }
    }
}
