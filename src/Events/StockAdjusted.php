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
        public Location $location,
        public Movement $movement,
        public int|float $previousQuantity,
        public int|float $newQuantity,
        public mixed $causable = null,
        public ?string $externalReference = null,
    ) {}
}
