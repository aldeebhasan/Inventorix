<?php

namespace Aldeebhasan\Inventorix\Traits\Concerns;

use Aldeebhasan\Inventorix\Inventorix;
use Aldeebhasan\Inventorix\Models\Location;
use Illuminate\Database\Eloquent\Builder;

trait TracksMovements
{
    public function movementHistory(Location|int|null $location = null, string|array|null $type = null, mixed $from = null, mixed $to = null): Builder
    {
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
