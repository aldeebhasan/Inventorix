<?php

namespace Aldeebhasan\Inventorix\Traits\Concerns;

use Aldeebhasan\Inventorix\Inventorix;
use Aldeebhasan\Inventorix\Models\Location;
use Aldeebhasan\Inventorix\Models\Movement;
use Illuminate\Database\Eloquent\Builder;

trait TracksMovements
{
    public function movementHistory(Location|int|null $location = null, string|array|null $type = null, mixed $from = null, mixed $to = null): Builder
    {
        if (! $this->isInventory()) {
            return Movement::query()->whereRaw('0 = 1');
        }

        $filters = [];

        if ($location !== null) {
            $filters['location'] = $location;
        }

        if ($type !== null) {
            $filters['type'] = $type;
        }

        if ($from !== null) {
            $filters['from'] = $from;
        }

        if ($to !== null) {
            $filters['to'] = $to;
        }

        return app(Inventorix::class)->movementsFor($this, $filters);
    }
}
