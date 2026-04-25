<?php

namespace Aldeebhasan\Inventorix\Contracts;

use Aldeebhasan\Inventorix\Models\Location;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

interface StockQueryInterface
{
    public function movementsFor(Model $stockable, array $filters = []): Builder;

    public function lowStockItems(?Location $location = null, ?string $stockableType = null, bool $includeChildren = false): Collection;

    /**
     * Return a Builder scoped to all movements produced by transactions whose causable
     * is the given model. Accepts the same $filters as movementsFor (location, type, from, to).
     */
    public function movementsByCausable(Model $causable, array $filters = []): Builder;
}
