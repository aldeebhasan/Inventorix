<?php

namespace Aldeebhasan\Inventorix\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Stock extends Model
{
    protected $fillable = [
        'stockable_type',
        'stockable_id',
        'location_id',
        'quantity',
        'reserved_quantity',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'reserved_quantity' => 'integer',
    ];

    public function getTable(): string
    {
        return config('inventorix.tables.stocks', 'inventorix_stocks');
    }

    protected function availableQuantity(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->quantity - $this->reserved_quantity,
        );
    }

    public function stockable(): MorphTo
    {
        return $this->morphTo();
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(Movement::class, 'stockable_id', 'stockable_id')
            ->where('stockable_type', $this->stockable_type)
            ->where('location_id', $this->location_id);
    }
}
