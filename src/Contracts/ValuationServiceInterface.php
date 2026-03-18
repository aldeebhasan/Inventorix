<?php

namespace Aldeebhasan\Inventorix\Contracts;

use Aldeebhasan\Inventorix\Models\Location;

interface ValuationServiceInterface
{
    public function totalValuation(?Location $location = null, string $costAttribute = 'cost_price'): float;
}
