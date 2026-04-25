<?php

namespace Aldeebhasan\Inventorix\Queries;

use Aldeebhasan\Inventorix\Enums\MovementType;
use Aldeebhasan\Inventorix\Models\Location;
use Aldeebhasan\Inventorix\Models\Movement;
use Aldeebhasan\Inventorix\Models\Stock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class StockVelocityQuery
{
    /**
     * Average daily consumption (Deduct quantity) over the last $days calendar days.
     * Returns 0.0 when there are no deduction movements in the window.
     */
    public function velocity(Model $stockable, Location $location, int $days = 30): float
    {
        $since = now()->subDays($days)->startOfDay();

        $total = Movement::where('stockable_type', get_class($stockable))
            ->where('stockable_id', $stockable->getKey())
            ->where('location_id', $location->id)
            ->where('type', MovementType::Deduct->value)
            ->where('created_at', '>=', $since)
            ->sum('quantity');

        return $days > 0 ? (float) $total / $days : 0.0;
    }

    /**
     * How many days the current available stock will last at the current velocity.
     * Returns INF when velocity is zero (stock will never run out at current rate).
     */
    public function daysOfStock(Model $stockable, Location $location, int $velocityDays = 30): float
    {
        $dailyVelocity = $this->velocity($stockable, $location, $velocityDays);

        if ($dailyVelocity <= 0.0) {
            return INF;
        }

        $stock = Stock::where('stockable_type', get_class($stockable))
            ->where('stockable_id', $stockable->getKey())
            ->where('location_id', $location->id)
            ->first();

        $available = $stock ? max(0.0, (float) $stock->quantity - (float) $stock->reserved_quantity) : 0.0;

        return $available / $dailyVelocity;
    }

    /**
     * The calendar day with the highest total deduction volume within the last $days days.
     * Returns null when no deduction movements exist in the window.
     */
    public function peakDemandDay(Model $stockable, Location $location, int $days = 90): ?Carbon
    {
        $since = now()->subDays($days)->startOfDay();

        $movementsTable = config('inventorix.tables.movements', 'inventorix_movements');

        /** @var object{demand_date: string}|null $row */
        $row = Movement::where('stockable_type', get_class($stockable))
            ->where('stockable_id', $stockable->getKey())
            ->where('location_id', $location->id)
            ->where('type', MovementType::Deduct->value)
            ->where('created_at', '>=', $since)
            ->select(DB::raw("DATE({$movementsTable}.created_at) as demand_date"), DB::raw('SUM(quantity) as total_qty'))
            ->groupBy('demand_date')
            ->orderByDesc('total_qty')
            ->first();

        return $row ? Carbon::parse($row->demand_date) : null;
    }
}
