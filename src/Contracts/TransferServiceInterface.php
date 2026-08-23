<?php

namespace Aldeebhasan\Inventorix\Contracts;

use Aldeebhasan\Inventorix\DTOs\StockOperationDto;
use Aldeebhasan\Inventorix\Models\Location;
use Aldeebhasan\Inventorix\Models\Transaction;
use Illuminate\Database\Eloquent\Model;

interface TransferServiceInterface
{
    public function transfer(Model $stockable, int|float $quantity, Location $from, Location $to, StockOperationDto $options = new StockOperationDto): bool;

    public function send(Model $stockable, int|float $quantity, Location $from, StockOperationDto $options = new StockOperationDto): Transaction;

    public function receive(Transaction $transaction, Model $stockable, int|float $quantity, Location $to, StockOperationDto $options = new StockOperationDto): bool;
}
