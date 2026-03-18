<?php

namespace Aldeebhasan\Inventorix\Events;

use Aldeebhasan\Inventorix\Models\Location;
use Aldeebhasan\Inventorix\Models\Movement;
use Aldeebhasan\Inventorix\Models\Stock;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

readonly class StockDeducted implements ShouldDispatchAfterCommit
{
    public function __construct(
        public mixed $stockable,
        public Stock $stock,
        public Movement $movement,
        public int|float $quantity,
        public Location $location,
    ) {}
}
