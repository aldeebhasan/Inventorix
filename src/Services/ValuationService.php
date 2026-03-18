<?php

namespace Aldeebhasan\Inventorix\Services;

use Aldeebhasan\Inventorix\Contracts\ValuationServiceInterface;
use Aldeebhasan\Inventorix\Models\Location;
use Aldeebhasan\Inventorix\Models\Stock;

class ValuationService implements ValuationServiceInterface
{
    public function totalValuation(?Location $location = null, string $costAttribute = 'cost_price'): float
    {
        $query = Stock::query();

        if ($location !== null) {
            $query->where('location_id', $location->id);
        }

        $total = 0.0;

        $query->with('stockable')->chunkById(500, function ($stocks) use (&$total, $costAttribute) {
            foreach ($stocks as $stock) {
                if ($stock->stockable && isset($stock->stockable->{$costAttribute})) {
                    $total += (float) $stock->quantity * (float) $stock->stockable->{$costAttribute};
                }
            }
        });

        return $total;
    }
}
