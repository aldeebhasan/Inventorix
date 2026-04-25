<?php

namespace Aldeebhasan\Inventorix\Traits;

use Aldeebhasan\Inventorix\Enums\CostingStrategy;
use Aldeebhasan\Inventorix\Models\Movement;
use Aldeebhasan\Inventorix\Models\Reservation;
use Aldeebhasan\Inventorix\Models\Stock;
use Aldeebhasan\Inventorix\Traits\Concerns\ManagesReservations;
use Aldeebhasan\Inventorix\Traits\Concerns\ManagesStock;
use Aldeebhasan\Inventorix\Traits\Concerns\ManagesThresholds;
use Aldeebhasan\Inventorix\Traits\Concerns\ManagesTransfers;
use Aldeebhasan\Inventorix\Traits\Concerns\QueriesStock;
use Aldeebhasan\Inventorix\Traits\Concerns\TracksMovements;
use Aldeebhasan\Inventorix\Traits\Concerns\TracksSerials;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * @method CostingStrategy inventorixCostingStrategy()
 */
trait HasInventory
{
    use ManagesReservations;
    use ManagesStock;
    use ManagesThresholds;
    use ManagesTransfers;
    use QueriesStock;
    use TracksMovements;
    use TracksSerials;

    public function stocks(): HasMany
    {
        return $this->hasMany(Stock::class, 'stockable_id')
            ->where('stockable_type', static::class);
    }

    public function movements(): MorphMany
    {
        return $this->morphMany(Movement::class, 'stockable');
    }

    public function reservations(): MorphMany
    {
        return $this->morphMany(Reservation::class, 'stockable');
    }
}
