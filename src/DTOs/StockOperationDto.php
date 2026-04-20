<?php

namespace Aldeebhasan\Inventorix\DTOs;

use Aldeebhasan\Inventorix\Enums\MovementType;
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
        /** Override the MovementType stored on the movement record. */
        public ?MovementType $movementType = null,
        /** Serial numbers to attach (on add) or detach (on deduct). */
        public array $serials = [],
    ) {}

    public function withMovementType(MovementType $type): self
    {
        return new self(
            transaction: $this->transaction,
            transactionType: $this->transactionType,
            causable: $this->causable,
            reference: $this->reference,
            cost: $this->cost,
            note: $this->note,
            createdBy: $this->createdBy,
            allowNegative: $this->allowNegative,
            expiresAt: $this->expiresAt,
            movementType: $type,
            serials: $this->serials,
        );
    }
}
