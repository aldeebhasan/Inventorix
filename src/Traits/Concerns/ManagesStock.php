<?php

namespace Aldeebhasan\Inventorix\Traits\Concerns;

use Aldeebhasan\Inventorix\Inventorix;
use Aldeebhasan\Inventorix\Models\Location;
use Aldeebhasan\Inventorix\Models\Stock;

trait ManagesStock
{
    public function addStock(int|float $quantity, Location|int $location, array $options = []): Stock
    {
        return app(Inventorix::class)->addStock($this, $quantity, $location, $options);
    }

    public function deductStock(int|float $quantity, Location|int $location, array $options = []): Stock
    {
        return app(Inventorix::class)->deductStock($this, $quantity, $location, $options);
    }

    public function adjustStock(int|float $newQuantity, Location|int $location, array $options = []): Stock
    {
        return app(Inventorix::class)->adjustStock($this, $newQuantity, $location, $options);
    }
}
