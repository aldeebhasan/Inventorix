<?php

namespace Aldeebhasan\Inventorix\Events;

use Aldeebhasan\Inventorix\Models\Location;
use Aldeebhasan\Inventorix\Models\Movement;
use Aldeebhasan\Inventorix\Models\Stock;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

readonly class StockAdded implements ShouldDispatchAfterCommit
{
    public function __construct(
        public mixed $stockable,
        public Stock $stock,
        public Movement $movement,
        public int $quantity,
        public Location $location,
    ) {}
}
