<?php

namespace Aldeebhasan\Inventorix\Services;

use Aldeebhasan\Inventorix\Contracts\RollbackServiceInterface;
use Aldeebhasan\Inventorix\Contracts\StockServiceInterface;
use Aldeebhasan\Inventorix\DTOs\StockOperationDto;
use Aldeebhasan\Inventorix\Enums\MovementType;
use Aldeebhasan\Inventorix\Enums\TransactionStatus;
use Aldeebhasan\Inventorix\Enums\TransactionType;
use Aldeebhasan\Inventorix\Events\TransactionRolledBack;
use Aldeebhasan\Inventorix\Exceptions\TransactionAlreadyRolledBackException;
use Aldeebhasan\Inventorix\Models\Movement;
use Aldeebhasan\Inventorix\Models\Transaction;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;

class RollbackService extends BaseService implements RollbackServiceInterface
{
    public function __construct(
        private readonly Dispatcher $events,
        private readonly StockServiceInterface $stocks,
        private readonly SerialService $serials,
    ) {}

    public function rollback(Transaction $transaction, StockOperationDto $options = new StockOperationDto): Transaction
    {
        if ($transaction->isRolledBack()) {
            throw new TransactionAlreadyRolledBackException(
                "Transaction [{$transaction->uuid}] has already been rolled back."
            );
        }

        return DB::transaction(function () use ($transaction, $options) {
            $movements = $transaction->movements()->with(['stockable', 'location'])->get();

            $reversalTransaction = Transaction::create([
                'type' => TransactionType::Reversal,
                'status' => TransactionStatus::Pending,
                'causable_type' => $options->causable ? get_class($options->causable) : $transaction->causable_type,
                'causable_id' => $options->causable ? $options->causable->getKey() : $transaction->causable_id,
                'note' => $options->note ?? "Reversal of transaction [{$transaction->uuid}]",
                'created_by' => $options->createdBy,
            ]);

            foreach ($movements as $movement) {
                /** @var Movement $movement */
                $this->compensate($movement, $reversalTransaction, $options);
            }

            $reversalTransaction->update(['status' => TransactionStatus::Committed]);

            $transaction->update([
                'status' => TransactionStatus::RolledBack,
                'reversed_by_transaction_id' => $reversalTransaction->id,
                'reversed_at' => now(),
            ]);

            if ($this->shouldDispatch('TransactionRolledBack')) {
                $this->events->dispatch(
                    new TransactionRolledBack($transaction, $reversalTransaction)
                );
            }

            return $reversalTransaction;
        });
    }

    /**
     * Reverse a single movement's stock effect via StockService (so threshold evaluation,
     * costing linkage, and events all fire normally) then compensate serials directly.
     */
    private function compensate(Movement $original, Transaction $reversalTransaction, StockOperationDto $options): void
    {
        $note = $options->note ?? "Reversal of movement [{$original->id}]";

        match ($original->type) {
            MovementType::Add => $this->reverseAdd($original, $reversalTransaction, $note, $options->createdBy),
            MovementType::Deduct => $this->reverseDeduct($original, $reversalTransaction, $note, $options->createdBy),
        };
    }

    /**
     * Reverse an Add: deduct via StockService (threshold eval + costing linkage),
     * then delete the Available serials that the original Add created.
     */
    private function reverseAdd(Movement $original, Transaction $reversalTransaction, string $note, ?int $createdBy): void
    {
        $dto = new StockOperationDto(
            transaction: $reversalTransaction,
            note: $note,
            createdBy: $createdBy,
            allowNegative: true,
            skipSerials: true,
        );

        $this->stocks->deduct($original->stockable, (float) $original->quantity, $original->location, $dto);

        $this->serials->rollbackAttach($original);
    }

    /**
     * Reverse a Deduct: add back via StockService (threshold eval), then restore the
     * Sold serials that the original Deduct marked — no new serials are created.
     */
    private function reverseDeduct(Movement $original, Transaction $reversalTransaction, string $note, ?int $createdBy): void
    {
        $cost = $original->cost_per_unit !== null ? (float) $original->cost_per_unit : null;

        $dto = new StockOperationDto(
            transaction: $reversalTransaction,
            cost: $cost,
            note: $note,
            createdBy: $createdBy,
            allowNegative: true,
            skipSerials: true,
        );

        $this->stocks->add($original->stockable, (float) $original->quantity, $original->location, $dto);

        $this->serials->rollbackDetach($original);
    }
}
