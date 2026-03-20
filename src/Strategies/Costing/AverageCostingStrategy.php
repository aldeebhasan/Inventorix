<?php

namespace Aldeebhasan\Inventorix\Strategies\Costing;

use Aldeebhasan\Inventorix\Models\Stock;
use Illuminate\Support\Collection;

/**
 * Weighted Average costing strategy.
 *
 * Computes a weighted average cost per unit across all costed inbound
 * movements and multiplies it by the current on-hand quantity.
 *
 * Average cost = sum(qty × cost_per_unit) / sum(qty)
 * Value = current_quantity × average_cost
 */
class AverageCostingStrategy extends AbstractCostingStrategy
{
    public function valuate(Stock $stock, Collection $movements): float
    {
        $inbound = $this->costedInboundMovements($movements);

        if ($inbound->isEmpty()) {
            return 0.0;
        }

        $totalQty = $inbound->sum(fn ($m) => (float) $m->quantity);

        if ($totalQty <= 0.0) {
            return 0.0;
        }

        $totalCost = $inbound->sum(fn ($m) => (float) $m->quantity * (float) $m->cost_per_unit);
        $avgCost = $totalCost / $totalQty;

        return (float) $stock->quantity * $avgCost;
    }
}
