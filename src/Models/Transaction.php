<?php

namespace Aldeebhasan\Inventorix\Models;

use Aldeebhasan\Inventorix\Enums\TransactionStatus;
use Aldeebhasan\Inventorix\Enums\TransactionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Transaction extends Model
{
    protected $fillable = [
        'uuid',
        'type',
        'status',
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

    public function movements(): HasMany
    {
        return $this->hasMany(Movement::class, 'transaction_id');
    }
}
