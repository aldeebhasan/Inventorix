<?php

namespace Aldeebhasan\Inventorix\Traits\Concerns;

use Aldeebhasan\Inventorix\Inventorix;
use Aldeebhasan\Inventorix\Models\Location;
use Aldeebhasan\Inventorix\Models\Reservation;
use Aldeebhasan\Inventorix\Models\Stock;
use Illuminate\Database\Eloquent\Model;

trait ManagesReservations
{
    public function reserve(int|float $quantity, Location|int $location, ?Model $reference = null, array $options = []): Reservation
    {
        return app(Inventorix::class)->reserve($this, $quantity, $location, $reference, $options);
    }

    public function releaseReservation(Reservation|int $reservation): bool
    {
        return app(Inventorix::class)->releaseReservation($reservation);
    }

    public function fulfillReservation(Reservation|int $reservation): Stock
    {
        return app(Inventorix::class)->fulfillReservation($reservation);
    }
}
