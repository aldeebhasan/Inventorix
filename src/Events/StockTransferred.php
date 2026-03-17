<?php

namespace Aldeebhasan\Inventorix\Events;

use Aldeebhasan\Inventorix\Models\Location;
use Aldeebhasan\Inventorix\Models\Transaction;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

readonly class StockTransferred implements ShouldDispatchAfterCommit
{
    public function __construct(
        public mixed $stockable,
        public int $quantity,
        public Location $fromLocation,
        public Location $toLocation,
        public Transaction $transaction,
    ) {}
}
