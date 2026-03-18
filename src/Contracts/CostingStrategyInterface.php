<?php

namespace Aldeebhasan\Inventorix\Contracts;

use Aldeebhasan\Inventorix\Models\Stock;
use Illuminate\Support\Collection;

interface CostingStrategyInterface
{
    /**
     * Calculate the total value of on-hand stock using this costing method.
     *
     * Only inbound movements that carry a cost_per_unit are used.
     * Returns 0.0 when no costed movements exist.
     */
    public function valuate(Stock $stock, Collection $movements): float;
}
