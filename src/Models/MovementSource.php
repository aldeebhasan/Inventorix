<?php

namespace Aldeebhasan\Inventorix\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $deduction_movement_id
 * @property int $source_movement_id
 * @property int|float $quantity
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class MovementSource extends Model
{
    protected $fillable = [
        'deduction_movement_id',
        'source_movement_id',
        'quantity',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
    ];

    public function getTable(): string
    {
        return config('inventorix.tables.movement_sources', 'inventorix_movement_sources');
    }

    public function deductionMovement(): BelongsTo
    {
        return $this->belongsTo(Movement::class, 'deduction_movement_id');
    }

    public function sourceMovement(): BelongsTo
    {
        return $this->belongsTo(Movement::class, 'source_movement_id');
    }
}
