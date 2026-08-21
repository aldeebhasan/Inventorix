<?php

namespace Aldeebhasan\Inventorix\Traits\Concerns;

use Aldeebhasan\Inventorix\DTOs\StockOperationDto;
use Aldeebhasan\Inventorix\Inventorix;
use Aldeebhasan\Inventorix\Models\Location;
use Aldeebhasan\Inventorix\Models\Reservation;
use Aldeebhasan\Inventorix\Models\Stock;

trait ManagesReservations
{
    public function reserve(int|float $quantity, Location|int $location, StockOperationDto $options = new StockOperationDto): ?Reservation
    {
        if (! $this->isInventory()) {
            return null;
        }

        return app(Inventorix::class)->reserve($this, $quantity, $location, $options);
    }

    public function releaseReservation(Reservation|int $reservation): bool
    {
        if (! $this->isInventory()) {
            return true;
        }

        return app(Inventorix::class)->releaseReservation($reservation);
    }

    public function fulfillReservation(Reservation|int $reservation): ?Stock
    {
        if (! $this->isInventory()) {
            return null;
        }

        return app(Inventorix::class)->fulfillReservation($reservation);
    }
}
