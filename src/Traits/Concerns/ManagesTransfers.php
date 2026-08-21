<?php

namespace Aldeebhasan\Inventorix\Traits\Concerns;

use Aldeebhasan\Inventorix\DTOs\StockOperationDto;
use Aldeebhasan\Inventorix\Inventorix;
use Aldeebhasan\Inventorix\Models\Location;

trait ManagesTransfers
{
    public function transferStock(int|float $quantity, Location|int $from, Location|int $to, StockOperationDto $options = new StockOperationDto): bool
    {
        if (! $this->isInventory()) {
            return true;
        }

        return app(Inventorix::class)->transfer($this, $quantity, $from, $to, $options);
    }
}
