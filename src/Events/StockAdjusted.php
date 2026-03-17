<?php

namespace Aldeebhasan\Inventorix\Events;

use Aldeebhasan\Inventorix\Models\Location;
use Aldeebhasan\Inventorix\Models\Movement;
use Aldeebhasan\Inventorix\Models\Stock;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

readonly class StockAdjusted implements ShouldDispatchAfterCommit
{
    public function __construct(
        public mixed $stockable,
        public Stock $stock,
        public Movement $movement,
        public int $previousQuantity,
        public int $newQuantity,
        public Location $location,
    ) {}
}
