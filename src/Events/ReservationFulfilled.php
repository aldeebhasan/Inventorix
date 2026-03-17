<?php

namespace Aldeebhasan\Inventorix\Events;

use Aldeebhasan\Inventorix\Models\Location;
use Aldeebhasan\Inventorix\Models\Reservation;
use Aldeebhasan\Inventorix\Models\Stock;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

readonly class ReservationFulfilled implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Reservation $reservation,
        public Stock $stock,
        public mixed $stockable,
        public Location $location,
    ) {}
}
