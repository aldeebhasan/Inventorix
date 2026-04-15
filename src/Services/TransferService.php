<?php

namespace Aldeebhasan\Inventorix\Services;

use Aldeebhasan\Inventorix\Contracts\StockServiceInterface;
use Aldeebhasan\Inventorix\Contracts\TransferServiceInterface;
use Aldeebhasan\Inventorix\DTOs\StockOperationDto;
use Aldeebhasan\Inventorix\Enums\MovementType;
use Aldeebhasan\Inventorix\Enums\TransactionStatus;
use Aldeebhasan\Inventorix\Enums\TransactionType;
use Aldeebhasan\Inventorix\Events\StockTransferred;
use Aldeebhasan\Inventorix\Exceptions\InvalidQuantityException;
use Aldeebhasan\Inventorix\Models\Location;
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

            $outDto = new StockOperationDto(
                transaction: $transaction,
                transactionType: $options->transactionType,
                causable: $options->causable,
                reference: $options->reference,
                cost: $options->cost,
                note: $options->note,
                createdBy: $options->createdBy,
                allowNegative: $options->allowNegative,
                expiresAt: $options->expiresAt,
                movementType: MovementType::TransferOut,
            );

            $this->stocks->deduct($stockable, $quantity, $from, $outDto);
            $this->stocks->add($stockable, $quantity, $to, $outDto->withMovementType(MovementType::TransferIn));

            if ($autoCreated) {
                $transaction->update(['status' => TransactionStatus::Committed]);
            }

            if ($this->shouldDispatch('StockTransferred')) {
                $this->events->dispatch(new StockTransferred($stockable, $quantity, $from, $to, $transaction));
            }
        });

        return true;
    }
}
