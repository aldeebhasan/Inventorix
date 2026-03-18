<?php

namespace Aldeebhasan\Inventorix\Events;

use Aldeebhasan\Inventorix\Models\Location;
use Aldeebhasan\Inventorix\Models\Stock;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

readonly class LowStockReached implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Stock $stock,
        public mixed $stockable,
        public int|float $threshold,
        public Location $location,
    ) {}
}
