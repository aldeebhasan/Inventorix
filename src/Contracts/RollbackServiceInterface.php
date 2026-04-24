<?php

namespace Aldeebhasan\Inventorix\Contracts;

use Aldeebhasan\Inventorix\DTOs\StockOperationDto;
use Aldeebhasan\Inventorix\Exceptions\TransactionAlreadyRolledBackException;
use Aldeebhasan\Inventorix\Models\Transaction;

interface RollbackServiceInterface
{
    /**
     * Reverse all stock movements of a committed transaction by creating compensation
     * movements under a new reversal transaction. The original transaction is marked
     * as RolledBack and linked to the reversal via reversed_by_transaction_id.
     *
     * @throws TransactionAlreadyRolledBackException
     */
    public function rollback(Transaction $transaction, StockOperationDto $options = new StockOperationDto): Transaction;
}
