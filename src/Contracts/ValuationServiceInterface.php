<?php

namespace Aldeebhasan\Inventorix\Contracts;

use Aldeebhasan\Inventorix\Models\Location;
use Illuminate\Database\Eloquent\Model;

interface ValuationServiceInterface
{
    public function totalValuation(?Location $location = null, ?Model $stockable = null, string $costAttribute = 'cost_price'): float;

    /**
     * Sum quantity × cost_per_unit for all Add movements tied to the given causable.
     * Only movements with a non-null cost_per_unit are included.
     */
    public function valuationByCausable(Model $causable): float;
}
