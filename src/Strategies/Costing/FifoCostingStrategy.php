<?php

namespace Aldeebhasan\Inventorix\Strategies\Costing;

use Aldeebhasan\Inventorix\Models\Movement;
use Aldeebhasan\Inventorix\Models\Stock;
use Illuminate\Support\Collection;

/**
 * First In First Out costing strategy.
 *
 * Oldest stock is assumed to be sold first, so the on-hand inventory
 * is valued at the cost of the most recently received batches.
 * We walk from newest inbound movement to oldest, accumulating
 * quantity until the current stock quantity is fully covered.
 */
class FifoCostingStrategy extends AbstractCostingStrategy
{
    public function valuate(Stock $stock, Collection $movements): float
    {
        $inbound = $this->costedInboundMovements($movements)
            ->sort(fn ($a, $b) => $this->compareNewestFirst($a, $b))
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

    /** Sort descending: newest first; use id as tiebreaker for same-second inserts. */
    private function compareNewestFirst(Movement $a, Movement $b): int
    {
        $aTs = $a->created_at?->timestamp ?? 0;
        $bTs = $b->created_at?->timestamp ?? 0;

        if ($aTs !== $bTs) {
            return $bTs <=> $aTs;
        }

        return ($b->id ?? 0) <=> ($a->id ?? 0);
    }
}
