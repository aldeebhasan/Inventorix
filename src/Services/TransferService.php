<?php

namespace Aldeebhasan\Inventorix\Services;

use Aldeebhasan\Inventorix\Contracts\StockServiceInterface;
use Aldeebhasan\Inventorix\Contracts\TransferServiceInterface;
use Aldeebhasan\Inventorix\DTOs\StockOperationDto;
use Aldeebhasan\Inventorix\Enums\MovementType;
use Aldeebhasan\Inventorix\Enums\TransactionStatus;
use Aldeebhasan\Inventorix\Enums\TransactionType;
use Aldeebhasan\Inventorix\Events\StockReceived;
use Aldeebhasan\Inventorix\Events\StockSent;
use Aldeebhasan\Inventorix\Events\StockTransferred;
use Aldeebhasan\Inventorix\Exceptions\InvalidQuantityException;
use Aldeebhasan\Inventorix\Models\Location;
use Aldeebhasan\Inventorix\Models\Movement;
use Aldeebhasan\Inventorix\Models\Transaction;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class TransferService extends BaseService implements TransferServiceInterface
{
    public function __construct(
        private readonly Dispatcher $events,
        private readonly StockServiceInterface $stocks,
    ) {}

    public function transfer(Model $stockable, int|float $quantity, Location $from, Location $to, StockOperationDto $options = new StockOperationDto): bool
    {
        if ($quantity <= 0) {
            throw new InvalidQuantityException;
        }

        DB::transaction(function () use ($stockable, $quantity, $from, $to, $options) {
            [$transaction, $autoCreated] = $this->resolveOrCreateTransaction($options, TransactionType::Transfer);

            $sharedDto = new StockOperationDto(
                transaction: $transaction,
                transactionType: $options->transactionType,
                causable: $options->causable,
                reference: $options->reference,
                cost: $options->cost,
                note: $options->note,
                createdBy: $options->createdBy,
                allowNegative: $options->allowNegative,
                expiresAt: $options->expiresAt,
            );

            $this->stocks->deduct($stockable, $quantity, $from, $sharedDto);

            $deductionCost = $this->resolveDeductionCost($transaction, $stockable, $from);

            $addDto = new StockOperationDto(
                transaction: $transaction,
                transactionType: $options->transactionType,
                causable: $options->causable,
                reference: $options->reference,
                cost: $deductionCost ?? $options->cost,
                note: $options->note,
                createdBy: $options->createdBy,
                allowNegative: $options->allowNegative,
                expiresAt: $options->expiresAt,
            );

            $this->stocks->add($stockable, $quantity, $to, $addDto);

            if ($autoCreated) {
                $transaction->update(['status' => TransactionStatus::Committed]);
            }

            if ($this->shouldDispatch('StockTransferred')) {
                $this->events->dispatch(new StockTransferred($stockable, $quantity, $from, $to, $transaction, $options->causable, $options->externalReference));
            }
        });

        return true;
    }

    public function send(Model $stockable, int|float $quantity, Location $from, StockOperationDto $options = new StockOperationDto): Transaction
    {
        if ($quantity <= 0) {
            throw new InvalidQuantityException;
        }

        return DB::transaction(function () use ($stockable, $quantity, $from, $options) {
            [$transaction, $autoCreated] = $this->resolveOrCreateTransaction($options, TransactionType::Transfer);

            $sharedDto = new StockOperationDto(
                transaction: $transaction,
                transactionType: $options->transactionType,
                causable: $options->causable,
                reference: $options->reference,
                cost: $options->cost,
                note: $options->note,
                createdBy: $options->createdBy,
                allowNegative: $options->allowNegative,
                expiresAt: $options->expiresAt,
            );

            $this->stocks->deduct($stockable, $quantity, $from, $sharedDto);

            if ($this->shouldDispatch('StockSent')) {
                $this->events->dispatch(new StockSent($stockable, $quantity, $from, $transaction, $options->causable, $options->externalReference));
            }

            return $transaction;
        });
    }

    private function resolveDeductionCost(Transaction $transaction, Model $stockable, ?Location $location = null): ?float
    {
        $query = Movement::where('transaction_id', $transaction->id)
            ->where('stockable_type', get_class($stockable))
            ->where('stockable_id', $stockable->getKey())
            ->where('type', MovementType::Deduct);

        if ($location) {
            $query->where('location_id', $location->id);
        }

        $cost = $query->latest('id')->value('cost_per_unit');

        return $cost !== null ? (float) $cost : null;
    }

    public function receive(Transaction $transaction, Model $stockable, int|float $quantity, Location $to, StockOperationDto $options = new StockOperationDto): bool
    {
        if ($quantity <= 0) {
            throw new InvalidQuantityException;
        }

        DB::transaction(function () use ($transaction, $stockable, $quantity, $to, $options) {
            $deductionCost = $this->resolveDeductionCost($transaction, $stockable);

            $sharedDto = new StockOperationDto(
                transaction: $transaction,
                transactionType: $options->transactionType,
                causable: $options->causable,
                reference: $options->reference,
                cost: $deductionCost ?? $options->cost,
                note: $options->note,
                createdBy: $options->createdBy,
                allowNegative: $options->allowNegative,
                expiresAt: $options->expiresAt,
            );

            $this->stocks->add($stockable, $quantity, $to, $sharedDto);

            $transaction->update(['status' => TransactionStatus::Committed]);

            if ($this->shouldDispatch('StockReceived')) {
                $this->events->dispatch(new StockReceived($stockable, $quantity, $to, $transaction, $options->causable, $options->externalReference));
            }
        });

        return true;
    }
}
