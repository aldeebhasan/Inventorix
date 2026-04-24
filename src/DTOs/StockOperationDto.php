<?php

namespace Aldeebhasan\Inventorix\DTOs;

use Aldeebhasan\Inventorix\Enums\TransactionType;
use Aldeebhasan\Inventorix\Models\Transaction;
use Illuminate\Database\Eloquent\Model;

final readonly class StockOperationDto
{
    public function __construct(
        public ?Transaction $transaction = null,
        public ?TransactionType $transactionType = null,
        public ?Model $causable = null,
        public ?Model $reference = null,
        /**
         * false = not provided; falls back to $stockable->cost_price
         * null  = explicitly no cost (overrides cost_price)
         * float = explicit cost per unit
         */
        public float|false|null $cost = false,
        public ?string $note = null,
        public ?int $createdBy = null,
        public bool $allowNegative = false,
        public ?\DateTimeInterface $expiresAt = null,
        /** Serial numbers to attach (on add) or detach (on deduct). */
        public array $serials = [],
        /**
         * When true the deduction is satisfied from reserved stock, so the
         * available-quantity check uses total quantity rather than available_quantity.
         * Used by ReservationService::fulfill().
         */
        public bool $fromReserved = false,
        /**
         * When true StockService skips the serial attach/detach step entirely.
         * Used by RollbackService, which manages serial compensation directly.
         */
        public bool $skipSerials = false,
    ) {}
}
