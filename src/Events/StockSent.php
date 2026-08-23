<?php

namespace Aldeebhasan\Inventorix\Events;

use Aldeebhasan\Inventorix\Models\Location;
use Aldeebhasan\Inventorix\Models\Transaction;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

readonly class StockSent implements ShouldDispatchAfterCommit
{
    public function __construct(
        public mixed $stockable,
        public int|float $quantity,
        public Location $fromLocation,
        public Transaction $transaction,
        public mixed $causable = null,
        public ?string $externalReference = null,
    ) {}
}
