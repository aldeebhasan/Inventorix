<?php

namespace Aldeebhasan\Inventorix\Events;

use Aldeebhasan\Inventorix\Models\Location;
use Aldeebhasan\Inventorix\Models\Reservation;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

readonly class StockReserved implements ShouldDispatchAfterCommit
{
    public function __construct(
        public mixed $stockable,
        public Reservation $reservation,
        public Location $location,
    ) {}
}
