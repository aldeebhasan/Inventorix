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

                return match ($movement->type) {
                    MovementType::Add, MovementType::TransferIn => true,
                    MovementType::Adjustment => (float) $movement->quantity > 0.0,
                    default => false,
                };
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
     *   2. Chunk over stocks with no costed movements and fall back to
     *      stock.quantity × stockable.{$costAttribute}.
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
        return $this->aggregateCostingTotal($location, $stockable)
            + $this->fallbackTotal($location, $stockable, $costAttribute);
    }

    /**
     * Single SQL SUM over costed inbound movements with remaining stock.
     *
     * Formula: SUM((quantity - COALESCE(consumed_quantity, 0)) * cost_per_unit)
     */
    private function aggregateCostingTotal(?Location $location, ?Model $stockable): float
    {
        return (float) Movement::query()
            ->whereNotNull('cost_per_unit')
            ->where(function ($q) {
                $q->whereIn('type', [MovementType::Add->value, MovementType::TransferIn->value])
                    ->orWhere(function ($q2) {
                        $q2->where('type', MovementType::Adjustment->value)
                            ->where('quantity', '>', 0);
                    });
            })
            ->whereRaw('(quantity - COALESCE(consumed_quantity, 0)) > 0')
            ->when($location !== null, fn ($q) => $q->where('location_id', $location->id))
            ->when($stockable !== null, fn ($q) => $q
                ->where('stockable_type', get_class($stockable))
                ->where('stockable_id', $stockable->getKey())
            )
            ->sum(DB::raw('(quantity - COALESCE(consumed_quantity, 0)) * cost_per_unit'));
    }

    /**
     * Chunk over stocks that have no costed inbound movements (legacy / uncosted data)
     * and multiply their quantity by the stockable's cost attribute.
     */
    private function fallbackTotal(?Location $location, ?Model $stockable, string $costAttribute): float
    {
        $movementsTable = (new Movement)->getTable();
        $stocksTable = (new Stock)->getTable();

        $query = Stock::query()
            ->with('stockable')
            ->whereNotExists(function ($q) use ($movementsTable, $stocksTable) {
                $q->from($movementsTable)
                    ->whereColumn("{$movementsTable}.stockable_id", "{$stocksTable}.stockable_id")
                    ->whereColumn("{$movementsTable}.stockable_type", "{$stocksTable}.stockable_type")
                    ->whereColumn("{$movementsTable}.location_id", "{$stocksTable}.location_id")
                    ->whereNotNull('cost_per_unit')
                    ->where(function ($q2) {
                        $q2->whereIn('type', [MovementType::Add->value, MovementType::TransferIn->value])
                            ->orWhere(function ($q3) {
                                $q3->where('type', MovementType::Adjustment->value)
                                    ->where('quantity', '>', 0);
                            });
                    });
            })
            ->when($location !== null, fn ($q) => $q->where('location_id', $location->id))
            ->when($stockable !== null, fn ($q) => $q
                ->where('stockable_type', get_class($stockable))
                ->where('stockable_id', $stockable->getKey())
            );

        $total = 0.0;

        $query->chunkById(500, function ($stocks) use (&$total, $costAttribute) {
            foreach ($stocks as $stock) {
                if ($stock->stockable !== null && isset($stock->stockable->{$costAttribute})) {
                    $total += (float) $stock->quantity * (float) $stock->stockable->{$costAttribute};
                }
            }
        });

        return $total;
    }
}
