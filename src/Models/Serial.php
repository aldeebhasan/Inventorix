<?php

namespace Aldeebhasan\Inventorix\Models;

use Aldeebhasan\Inventorix\Enums\SerialStatus;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int $id
 * @property string $stockable_type
 * @property int $stockable_id
 * @property int $location_id
 * @property Location $location
 * @property string $serial_number
 * @property SerialStatus $status
 * @property int|null $reservation_id
 * @property Reservation|null $reservation
 * @property int|null $movement_id
 * @property Movement|null $movement
 * @property array|null $meta
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Serial extends Model
{
    protected $fillable = [
        'stockable_type',
        'stockable_id',
        'location_id',
        'serial_number',
        'status',
        'reservation_id',
        'movement_id',
        'meta',
    ];

    protected $casts = [
        'status' => SerialStatus::class,
        'meta' => 'array',
    ];

    public function getTable(): string
    {
        return config('inventorix.tables.serials', 'inventorix_serials');
    }

    public function stockable(): MorphTo
    {
        return $this->morphTo();
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(Movement::class, 'movement_id');
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class, 'reservation_id');
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('status', SerialStatus::Available->value);
    }

    public function scopeReserved(Builder $query): Builder
    {
        return $query->where('status', SerialStatus::Reserved->value);
    }

    public function scopeAtLocation(Builder $query, Location $location): Builder
    {
        return $query->where('location_id', $location->id);
    }
}
