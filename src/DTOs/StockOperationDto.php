<?php

namespace Aldeebhasan\Inventorix\DTOs;

use Aldeebhasan\Inventorix\Enums\TransactionType;
use Aldeebhasan\Inventorix\Models\Transaction;
use Illuminate\Database\Eloquent\Model;

final readonly class StockOperationDto
{
    public function __construct(
        public readonly ?Transaction $transaction = null,
        public readonly ?TransactionType $transactionType = null,
        public readonly ?Model $causable = null,
        public readonly ?Model $reference = null,
        /**
         * false = not provided; falls back to $stockable->cost_price
         * null  = explicitly no cost (overrides cost_price)
         * float = explicit cost per unit
         */
        public readonly float|false|null $cost = false,
        public readonly ?string $note = null,
        public readonly ?int $createdBy = null,
        public readonly bool $allowNegative = false,
        public readonly ?\DateTimeInterface $expiresAt = null,
    ) {
    }
}
