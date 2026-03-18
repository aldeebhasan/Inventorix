<?php

namespace Aldeebhasan\Inventorix\Queries;

use Aldeebhasan\Inventorix\Contracts\StockQueryInterface;
use Aldeebhasan\Inventorix\Models\Location;
use Aldeebhasan\Inventorix\Models\Movement;
use Aldeebhasan\Inventorix\Models\Stock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class StockQueries implements StockQueryInterface
{
    public function movementsFor(Model $stockable, array $filters = []): Builder
    {
        $query = Movement::where('stockable_type', get_class($stockable))
            ->where('stockable_id', $stockable->getKey());

        if (isset($filters['location'])) {
            $location = $filters['location'];
            $locationId = $location instanceof Location ? $location->id : $location;
            $query->where('location_id', $locationId);
        }

        if (isset($filters['type'])) {
            $type = $filters['type'];
            if (is_array($type)) {
                $query->whereIn('type', $type);
            } else {
                $query->where('type', $type);
            }
        }

        if (isset($filters['from'])) {
            $query->where('created_at', '>=', $filters['from']);
        }

        if (isset($filters['to'])) {
            $query->where('created_at', '<=', $filters['to']);
        }

        return $query;
    }

    public function lowStockItems(?Location $location = null, ?string $stockableType = null): Collection
    {
        $stocksTable = config('inventorix.tables.stocks', 'inventorix_stocks');
        $thresholdsTable = config('inventorix.tables.thresholds', 'inventorix_thresholds');

        $query = Stock::join($thresholdsTable, function ($join) use ($stocksTable, $thresholdsTable) {
            $join->on("{$thresholdsTable}.stockable_type", '=', "{$stocksTable}.stockable_type")
                ->on("{$thresholdsTable}.stockable_id", '=', "{$stocksTable}.stockable_id")
                ->where(function ($q) use ($stocksTable, $thresholdsTable) {
                    $q->whereColumn("{$thresholdsTable}.location_id", "{$stocksTable}.location_id")
                        ->orWhereNull("{$thresholdsTable}.location_id");
                });
        })
            ->whereColumn("{$stocksTable}.quantity", '<=', "{$thresholdsTable}.min_quantity")
            ->select("{$stocksTable}.*");

        if ($location !== null) {
            $query->where("{$stocksTable}.location_id", $location->id);
        }

        if ($stockableType !== null) {
            $query->where("{$stocksTable}.stockable_type", $stockableType);
        }

        return $query->get();
    }
}
