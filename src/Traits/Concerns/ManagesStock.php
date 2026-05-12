<?php

namespace Aldeebhasan\Inventorix\Traits\Concerns;

use Aldeebhasan\Inventorix\DTOs\StockOperationDto;
use Aldeebhasan\Inventorix\Inventorix;
use Aldeebhasan\Inventorix\Models\Location;
use Aldeebhasan\Inventorix\Models\Stock;
use Illuminate\Database\Eloquent\Model;

trait ManagesStock
{
    public function addStock(int|float $quantity, Location|int $location, StockOperationDto $options = new StockOperationDto): Stock
    {
        return app(Inventorix::class)->addStock($this, $quantity, $location, $options);
    }

    public function deductStock(int|float $quantity, Location|int $location, StockOperationDto $options = new StockOperationDto): Stock
    {
        return app(Inventorix::class)->deductStock($this, $quantity, $location, $options);
    }

    public function adjustStock(int|float $newQuantity, Location|int $location, StockOperationDto $options = new StockOperationDto): Stock
    {
        return app(Inventorix::class)->adjustStock($this, $newQuantity, $location, $options);
    }

    public function adjustStockByReference(Model $reference, int|float $newQuantity, Location|int $location, StockOperationDto $options = new StockOperationDto): Stock
    {
        return app(Inventorix::class)->adjustByReference($this, $reference, $newQuantity, $location, $options);
    }
}
