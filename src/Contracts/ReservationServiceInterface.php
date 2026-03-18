<?php

namespace Aldeebhasan\Inventorix\Contracts;

use Aldeebhasan\Inventorix\Models\Location;
use Aldeebhasan\Inventorix\Models\Reservation;
use Aldeebhasan\Inventorix\Models\Stock;
use Illuminate\Database\Eloquent\Model;

interface ReservationServiceInterface
{
    public function reserve(Model $stockable, int|float $quantity, Location $location, ?Model $reference = null, array $options = []): Reservation;

    public function release(Reservation|int $reservation): bool;

    public function fulfill(Reservation|int $reservation): Stock;
}
