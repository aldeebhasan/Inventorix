<?php

namespace Aldeebhasan\Inventorix\Contracts;

use Aldeebhasan\Inventorix\Models\Location;
use Aldeebhasan\Inventorix\Models\Stock;
use Illuminate\Database\Eloquent\Model;

interface StockServiceInterface
{
    public function add(Model $stockable, int|float $quantity, Location $location, array $options = []): Stock;

    public function deduct(Model $stockable, int|float $quantity, Location $location, array $options = []): Stock;

    public function adjust(Model $stockable, int|float $newQuantity, Location $location, array $options = []): Stock;
}
