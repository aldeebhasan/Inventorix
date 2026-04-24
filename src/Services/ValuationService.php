<?php

namespace Aldeebhasan\Inventorix\Services;

use Aldeebhasan\Inventorix\Contracts\ValuationServiceInterface;
use Aldeebhasan\Inventorix\Enums\MovementType;
use Aldeebhasan\Inventorix\Models\Location;
use Aldeebhasan\Inventorix\Models\Movement;
use Aldeebhasan\Inventorix\Models\Stock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ValuationService implements ValuationServiceInterface
{
    /**
     * Evaluate the total cost of remaining stock from an in-memory movements collection.
     *
     * Sums (quantity - consumed_quantity) × cost_per_unit for every costed inbound lot.
     * Useful for single-stock evaluation or testing without hitting the database.
     */
    public function evaluate(Collection $movements): float
    {
        return $movements
            ->filter(function ($movement) {
                if ($movement->cost_per_unit === null) {
                    return false;
                }

                return $movement->type === MovementType::Add;
            })
            ->sum(function ($movement) {
                $remaining = (float) $movement->quantity - (float) $movement->consumed_quantity;

                return $remaining > 0.0 ? $remaining * (float) $movement->cost_per_unit : 0.0;
            });
    }

    /**
     * Calculate total inventory valuation directly from the database.
     *
     * Two-pass approach:
     *   1. Aggregate (quantity - consumed_quantity) × cost_per_unit in a single SQL SUM
     *      for all stocks that have at least one costed inbound movement.
     *
     *   Formula: SUM((quantity - COALESCE(consumed_quantity, 0)) * cost_per_unit)
     *
     * @param  Location|null  $location  Scope to a specific warehouse/location.
     * @param  Model|null  $stockable  Scope to a specific stockable item.
     * @param  string  $costAttribute  Attribute name on the stockable used as fallback cost.
     */
    public function totalValuation(
        ?Location $location = null,
        ?Model $stockable = null,
        string $costAttribute = 'cost_price'
    ): float {
        return (float) Movement::query()
            ->whereNotNull('cost_per_unit')
            ->where('type', MovementType::Add->value)
            ->whereRaw('(quantity - COALESCE(consumed_quantity, 0)) > 0')
            ->when($location !== null, fn ($q) => $q->where('location_id', $location->id))
            ->when($stockable !== null, fn ($q) => $q
                ->where('stockable_type', get_class($stockable))
                ->where('stockable_id', $stockable->getKey())
            )
            ->sum(DB::raw('(quantity - COALESCE(consumed_quantity, 0)) * cost_per_unit'));
    }
}
