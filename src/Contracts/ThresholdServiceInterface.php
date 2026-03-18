<?php

namespace Aldeebhasan\Inventorix\Contracts;

use Aldeebhasan\Inventorix\Models\Location;
use Aldeebhasan\Inventorix\Models\Stock;
use Illuminate\Database\Eloquent\Model;

interface ThresholdServiceInterface
{
    public function evaluate(mixed $stockable, Stock $stock, Location $location): void;

    public function check(Model $stockable, ?Location $location = null): void;
}
