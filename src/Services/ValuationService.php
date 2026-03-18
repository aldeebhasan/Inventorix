<?php

namespace Aldeebhasan\Inventorix\Services;

use Aldeebhasan\Inventorix\Contracts\CostingStrategyInterface;
use Aldeebhasan\Inventorix\Contracts\ValuationServiceInterface;
use Aldeebhasan\Inventorix\Models\Location;
use Aldeebhasan\Inventorix\Models\Stock;

class ValuationService implements ValuationServiceInterface
{
    public function __construct(private readonly CostingStrategyInterface $strategy) {}

    public function totalValuation(?Location $location = null, string $costAttribute = 'cost_price'): float
    {
        $query = Stock::query();

        if ($location !== null) {
            $query->where('location_id', $location->id);
        }

        $total = 0.0;

        // Eager-load stockable for cost_price fallback; movements are queried per-stock
        // because the movements() relation carries instance-level constraints
        // (stockable_type + location_id) that break batch eager loading.
        $query->with('stockable')->chunkById(500, function ($stocks) use (&$total, $costAttribute) {
            foreach ($stocks as $stock) {
                $movements = $stock->movements()->get();

                if ($movements->whereNotNull('cost_per_unit')->isNotEmpty()) {
                    $total += $this->strategy->valuate($stock, $movements);
                } elseif ($stock->stockable !== null && isset($stock->stockable->{$costAttribute})) {
                    $total += (float) $stock->quantity * (float) $stock->stockable->{$costAttribute};
                }
            }
        });

        return $total;
    }
}
