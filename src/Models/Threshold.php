<?php

namespace Aldeebhasan\Inventorix\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Threshold extends Model
{
    protected $fillable = [
        'stockable_type',
        'stockable_id',
        'location_id',
        'min_quantity',
        'max_quantity',
    ];

    protected $casts = [
        'min_quantity' => 'decimal:4',
        'max_quantity' => 'decimal:4',
    ];

    public function getTable(): string
    {
        return config('inventorix.tables.thresholds', 'inventorix_thresholds');
    }

    public function stockable(): MorphTo
    {
        return $this->morphTo();
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }
}
