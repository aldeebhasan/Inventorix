<?php

namespace Aldeebhasan\Inventorix\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int $id
 * @property string|null $stockable_type
 * @property int|null $stockable_id
 * @property int|null $location_id
 * @property Location $location
 * @property int|float $min_quantity
 * @property int|float $max_quantity
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
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
