<?php

namespace Aldeebhasan\Inventorix\Strategies\Costing;

use Aldeebhasan\Inventorix\Models\Movement;
use Aldeebhasan\Inventorix\Models\Stock;
use Illuminate\Support\Collection;

/**
 * Last In First Out costing strategy.
 *
 * Newest stock is assumed to be sold first, so the on-hand inventory
 * is valued at the cost of the oldest remaining batches.
 * We walk from oldest inbound movement to newest, accumulating
 * quantity until the current stock quantity is fully covered.
 */
class LifoCostingStrategy extends AbstractCostingStrategy
{
    public function valuate(Stock $stock, Collection $movements): float
    {
        $inbound = $this->costedInboundMovements($movements)
            ->sort(fn ($a, $b) => $this->compareOldestFirst($a, $b))
            ->values();

        if ($inbound->isEmpty()) {
            return 0.0;
        }

        $remaining = (float) $stock->quantity;
        $value = 0.0;

        foreach ($inbound as $movement) {
            if ($remaining <= 0.0) {
                break;
            }

            $batchQty = min((float) $movement->quantity, $remaining);
            $value += $batchQty * (float) $movement->cost_per_unit;
            $remaining -= $batchQty;
        }

        return $value;
    }

    /** Sort ascending: oldest first; use id as tiebreaker for same-second inserts. */
    private function compareOldestFirst(Movement $a, Movement $b): int
    {
        $aTs = $a->created_at?->timestamp ?? 0;
        $bTs = $b->created_at?->timestamp ?? 0;

        if ($aTs !== $bTs) {
            return $aTs <=> $bTs;
        }

        return ($a->id ?? 0) <=> ($b->id ?? 0);
    }
}
