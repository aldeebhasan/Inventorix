<?php

namespace Aldeebhasan\Inventorix\Models;

use Aldeebhasan\Inventorix\Enums\MovementType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Movement extends Model
{
    protected $fillable = [
        'stockable_type',
        'stockable_id',
        'location_id',
        'transaction_id',
        'type',
        'quantity',
        'before_quantity',
        'after_quantity',
        'reference_type',
        'reference_id',
        'note',
        'created_by',
    ];

    protected $casts = [
        'type' => MovementType::class,
        'quantity' => 'decimal:4',
        'before_quantity' => 'decimal:4',
        'after_quantity' => 'decimal:4',
    ];

    public function getTable(): string
    {
        return config('inventorix.tables.movements', 'inventorix_movements');
    }

    public function stockable(): MorphTo
    {
        return $this->morphTo();
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'transaction_id');
    }

    public function reference(): MorphTo
    {
        return $this->morphTo('reference');
    }
}
