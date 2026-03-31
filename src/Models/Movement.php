<?php

namespace Aldeebhasan\Inventorix\Models;

use Aldeebhasan\Inventorix\Enums\MovementType;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int $id
 * @property string $stockable_type
 * @property int $stockable_id
 * @property int $location_id
 * @property Location $location
 * @property int $transaction_id
 * @property MovementType $type
 * @property int|float $quantity
 * @property int|float $consumed_quantity
 * @property int|float|null $cost_per_unit
 * @property int|float $before_quantity
 * @property int|float $after_quantity
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property string|null $note
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Movement extends Model
{
    protected $fillable = [
        'stockable_type',
        'stockable_id',
        'location_id',
        'transaction_id',
        'type',
        'quantity',
        'consumed_quantity',
        'cost_per_unit',
        'before_quantity',
        'after_quantity',
        'reference_type',
        'reference_id',
        'note',
        'created_by',
        'created_at',
    ];

    protected $casts = [
        'type' => MovementType::class,
        'quantity' => 'decimal:4',
        'consumed_quantity' => 'decimal:4',
        'cost_per_unit' => 'decimal:4',
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

    /**
     * The lot allocations that link this deduction movement to its source Add movements.
     */
    public function sources(): HasMany
    {
        return $this->hasMany(MovementSource::class, 'deduction_movement_id');
    }

    /**
     * The deduction allocations that have consumed from this inbound movement.
     */
    public function consumedBy(): HasMany
    {
        return $this->hasMany(MovementSource::class, 'source_movement_id');
    }
}
