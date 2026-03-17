<?php

namespace Aldeebhasan\Inventorix\Traits;

use Aldeebhasan\Inventorix\Inventorix;
use Aldeebhasan\Inventorix\Models\Location;
use Aldeebhasan\Inventorix\Models\Stock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

trait HasLocation
{
    public function stockedItems(): HasMany
    {
        return $this->hasMany(Stock::class, 'location_id');
    }

    public function activeStock(): Collection
    {
        return Stock::where('location_id', $this->getKey())
            ->where('quantity', '>', 0)
            ->get();
    }

    public function transferTo(Location $target, Model $product, int $qty): bool
    {
        /** @var Location $this */
        return app(Inventorix::class)->transfer($product, $qty, $this, $target);
    }
}
