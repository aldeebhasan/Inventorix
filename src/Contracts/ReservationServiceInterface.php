<?php

namespace Aldeebhasan\Inventorix\Contracts;

use Aldeebhasan\Inventorix\DTOs\StockOperationDto;
use Aldeebhasan\Inventorix\Models\Location;
use Aldeebhasan\Inventorix\Models\Reservation;
use Aldeebhasan\Inventorix\Models\Stock;
use Illuminate\Database\Eloquent\Model;

interface ReservationServiceInterface
{
    public function reserve(Model $stockable, int|float $quantity, Location $location, StockOperationDto $options = new StockOperationDto): Reservation;

    public function release(Reservation|int $reservation): bool;

    public function fulfill(Reservation|int $reservation): Stock;
}
