<?php

namespace Aldeebhasan\Inventorix\Contracts;

use Aldeebhasan\Inventorix\Models\Location;
use Illuminate\Database\Eloquent\Model;

interface ValuationServiceInterface
{
    public function totalValuation(?Location $location = null, ?Model $stockable = null, string $costAttribute = 'cost_price'): float;
}
