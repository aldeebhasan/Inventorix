<?php

namespace Aldeebhasan\Inventorix\Models;

use Aldeebhasan\Inventorix\Enums\TransactionStatus;
use Aldeebhasan\Inventorix\Enums\TransactionType;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property TransactionType $type
 * @property TransactionStatus $status
 * @property string|null $causable_type
 * @property int|null $causable_id
 * @property string|null $note
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Transaction extends Model
{
    protected $fillable = [
        'uuid',
        'type',
        'status',
        'causable_type',
        'causable_id',
        'note',
        'created_by',
    ];

    protected $casts = [
        'type' => TransactionType::class,
        'status' => TransactionStatus::class,
    ];

    public function getTable(): string
    {
        return config('inventorix.tables.transactions', 'inventorix_transactions');
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Transaction $transaction) {
            if (empty($transaction->uuid)) {
                $transaction->uuid = (string) Str::uuid();
            }
        });
    }

    public function causable(): MorphTo
    {
        return $this->morphTo();
    }

    public function movements(): HasMany
    {
        return $this->hasMany(Movement::class, 'transaction_id');
    }
}
