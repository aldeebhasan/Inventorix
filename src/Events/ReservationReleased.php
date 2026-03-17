<?php

namespace Aldeebhasan\Inventorix\Events;

use Aldeebhasan\Inventorix\Models\Location;
use Aldeebhasan\Inventorix\Models\Reservation;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

readonly class ReservationReleased implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Reservation $reservation,
        public mixed $stockable,
        public Location $location,
    ) {}
}
