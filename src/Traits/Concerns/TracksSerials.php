<?php

namespace Aldeebhasan\Inventorix\Traits\Concerns;

use Aldeebhasan\Inventorix\Enums\SerialStatus;
use Aldeebhasan\Inventorix\Models\Location;
use Aldeebhasan\Inventorix\Models\Serial;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait TracksSerials
{
    public function serials(): MorphMany
    {
        return $this->morphMany(Serial::class, 'stockable');
    }

    public function availableSerials(?Location $location = null): MorphMany
    {
        $query = $this->serials()->where('status', SerialStatus::Available->value);

        if ($location !== null) {
            $query->where('location_id', $location->id);
        }

        return $query;
    }

    public function reservedSerials(?Location $location = null): MorphMany
    {
        $query = $this->serials()->where('status', SerialStatus::Reserved->value);

        if ($location !== null) {
            $query->where('location_id', $location->id);
        }

        return $query;
    }
}
