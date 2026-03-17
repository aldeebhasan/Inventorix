<?php

namespace Aldeebhasan\Inventorix\Models;

use Aldeebhasan\Inventorix\Enums\ReservationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Reservation extends Model
{
    protected $fillable = [
        'stockable_type',
        'stockable_id',
        'location_id',
        'quantity',
        'status',
        'reference_type',
        'reference_id',
        'note',
        'created_by',
        'expires_at',
    ];

    protected $casts = [
        'status' => ReservationStatus::class,
        'expires_at' => 'datetime',
        'quantity' => 'integer',
    ];

    public function getTable(): string
    {
        return config('inventorix.tables.reservations', 'inventorix_reservations');
    }

    public function stockable(): MorphTo
    {
        return $this->morphTo();
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function reference(): MorphTo
    {
        return $this->morphTo('reference');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', ReservationStatus::Pending->value);
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('expires_at', '<', now())
            ->where('status', ReservationStatus::Pending->value);
    }
}
