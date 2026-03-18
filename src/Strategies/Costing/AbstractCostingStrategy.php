<?php

namespace Aldeebhasan\Inventorix\Strategies\Costing;

use Aldeebhasan\Inventorix\Contracts\CostingStrategyInterface;
use Aldeebhasan\Inventorix\Enums\MovementType;
use Illuminate\Support\Collection;

abstract class AbstractCostingStrategy implements CostingStrategyInterface
{
    /**
     * Filter movements to only costed inbound entries.
     *
     * Inbound types: Add, TransferIn, and positive Adjustments.
     * Movements with null cost_per_unit are excluded.
     */
    protected function costedInboundMovements(Collection $movements): Collection
    {
        return $movements->filter(function ($movement) {
            if ($movement->cost_per_unit === null) {
                return false;
            }

            return match ($movement->type) {
                MovementType::Add, MovementType::TransferIn => true,
                MovementType::Adjustment => (float) $movement->quantity > 0.0,
                default => false,
            };
        });
    }
}
