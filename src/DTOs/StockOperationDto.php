<?php

namespace Aldeebhasan\Inventorix\DTOs;

use Aldeebhasan\Inventorix\Enums\TransactionType;
use Aldeebhasan\Inventorix\Models\Transaction;
use Illuminate\Database\Eloquent\Model;

final class StockOperationDto
{
    private bool $skipSerials = false;

    private bool $fromReserved = false;

    public function __construct(
        public ?Transaction $transaction = null,
        public ?TransactionType $transactionType = null,
        public ?Model $causable = null,
        public ?Model $reference = null,
        public ?float $cost = null,
        public ?string $note = null,
        public ?int $createdBy = null,
        public bool $allowNegative = false,
        public ?\DateTimeInterface $expiresAt = null,
        /** Serial numbers to attach (on add) or detach (on deduct). */
        public array $serials = [],
        public ?string $lotReference = null,
        public ?string $externalReference = null,
    ) {}

    public function skipSerials(): self
    {
        $this->skipSerials = true;

        return $this;
    }

    public function shouldSkipSerials(): bool
    {
        return $this->skipSerials;
    }

    public function fromReservation(): self
    {
        $this->fromReserved = true;

        return $this;
    }

    public function isFromReservation(): bool
    {
        return $this->fromReserved;
    }
}
