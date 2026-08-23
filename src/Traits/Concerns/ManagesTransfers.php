<?php

namespace Aldeebhasan\Inventorix\Traits\Concerns;

use Aldeebhasan\Inventorix\DTOs\StockOperationDto;
use Aldeebhasan\Inventorix\Inventorix;
use Aldeebhasan\Inventorix\Models\Location;
use Aldeebhasan\Inventorix\Models\Transaction;

trait ManagesTransfers
{
    public function transferStock(int|float $quantity, Location|int $from, Location|int $to, StockOperationDto $options = new StockOperationDto): bool
    {
        if (! $this->isInventory()) {
            return true;
        }

        return app(Inventorix::class)->transfer($this, $quantity, $from, $to, $options);
    }

    public function sendStock(int|float $quantity, Location|int $from, StockOperationDto $options = new StockOperationDto): Transaction
    {
        return app(Inventorix::class)->send($this, $quantity, $from, $options);
    }

    public function receiveStock(Transaction $transaction, int|float $quantity, Location|int $to, StockOperationDto $options = new StockOperationDto): bool
    {
        return app(Inventorix::class)->receive($transaction, $this, $quantity, $to, $options);
    }
}
