<?php

namespace Aldeebhasan\Inventorix\Events;

use Aldeebhasan\Inventorix\Models\Transaction;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

readonly class TransactionRolledBack implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Transaction $originalTransaction,
        public Transaction $reversalTransaction,
    ) {}
}
