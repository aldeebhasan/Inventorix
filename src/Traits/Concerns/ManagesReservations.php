<?php

namespace Aldeebhasan\Inventorix\Traits\Concerns;

use Aldeebhasan\Inventorix\DTOs\StockOperationDto;
use Aldeebhasan\Inventorix\Inventorix;
use Aldeebhasan\Inventorix\Models\Location;
use Aldeebhasan\Inventorix\Models\Reservation;
use Aldeebhasan\Inventorix\Models\Stock;

trait ManagesReservations
{
    public function reserve(int|float $quantity, Location|int $location, StockOperationDto $options = new StockOperationDto): Reservation
    {
        return app(Inventorix::class)->reserve($this, $quantity, $location, $options);
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
