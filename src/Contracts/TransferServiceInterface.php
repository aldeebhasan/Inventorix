<?php

namespace Aldeebhasan\Inventorix\Contracts;

use Aldeebhasan\Inventorix\Models\Location;
use Illuminate\Database\Eloquent\Model;

interface TransferServiceInterface
{
    public function transfer(Model $stockable, int|float $quantity, Location $from, Location $to, array $options = []): bool;
}
