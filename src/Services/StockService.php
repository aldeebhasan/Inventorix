<?php

namespace Aldeebhasan\Inventorix\Services;

use Aldeebhasan\Inventorix\Contracts\StockServiceInterface;
use Aldeebhasan\Inventorix\Contracts\ThresholdServiceInterface;
use Aldeebhasan\Inventorix\DTOs\StockOperationDto;
use Aldeebhasan\Inventorix\Enums\MovementType;
use Aldeebhasan\Inventorix\Enums\TransactionStatus;
use Aldeebhasan\Inventorix\Enums\TransactionType;
use Aldeebhasan\Inventorix\Events\StockAdded;
use Aldeebhasan\Inventorix\Events\StockAdjusted;
use Aldeebhasan\Inventorix\Events\StockDeducted;
use Aldeebhasan\Inventorix\Exceptions\InsufficientStockException;
use Aldeebhasan\Inventorix\Exceptions\InvalidQuantityException;
use Aldeebhasan\Inventorix\Models\Location;
use Aldeebhasan\Inventorix\Models\Movement;
use Aldeebhasan\Inventorix\Models\Stock;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class StockService extends BaseService implements StockServiceInterface
{
    public function __construct(
        private readonly Dispatcher $events,
        private readonly ThresholdServiceInterface $thresholds,
        private readonly CostingService $costing
    ) {}

    public function add(Model $stockable, int|float $quantity, Location $location, StockOperationDto $options = new StockOperationDto): Stock
    {
        if ($quantity <= 0) {
            throw new InvalidQuantityException;
        }

        return DB::transaction(function () use ($stockable, $quantity, $location, $options) {
            [$transaction, $autoCreated] = $this->resolveOrCreateTransaction($options, TransactionType::Manual);

            $stock = $this->findOrCreateStock($stockable, $location);
            $beforeQuantity = $stock->quantity;

            $stock->increment('quantity', $quantity);
            $stock->refresh();

            $movement = $this->recordMovement([
                'stockable_type' => get_class($stockable),
                'stockable_id' => $stockable->getKey(),
                'location_id' => $location->id,
                'transaction_id' => $transaction->id,
                'type' => $options->movementType ?? MovementType::Add,
                'quantity' => $quantity,
                'cost_per_unit' => $this->resolveCost($stockable, $options),
                'before_quantity' => $beforeQuantity,
                'after_quantity' => $stock->quantity,
                'reference_type' => $options->reference ? get_class($options->reference) : null,
                'reference_id' => $options->reference?->getKey(),
                'note' => $options->note,
                'created_by' => $options->createdBy,
            ]);

            if ($autoCreated) {
                $transaction->update(['status' => TransactionStatus::Committed]);
            }

            if ($this->shouldDispatch('StockAdded')) {
                $this->events->dispatch(new StockAdded($stockable, $stock, $movement, $quantity, $location));
            }

            $this->thresholds->evaluate($stockable, $stock, $location);

            return $stock;
        });
    }

    public function deduct(Model $stockable, int|float $quantity, Location $location, StockOperationDto $options = new StockOperationDto): Stock
    {
        if ($quantity <= 0) {
            throw new InvalidQuantityException;
        }

        return DB::transaction(function () use ($stockable, $quantity, $location, $options) {
            [$transaction, $autoCreated] = $this->resolveOrCreateTransaction($options, TransactionType::Manual);

            $stock = $this->findOrCreateStock($stockable, $location);
            $allowNegative = $options->allowNegative || config('inventorix.allow_negative_stock', false);

            if (! $allowNegative && $stock->available_quantity < $quantity) {
                throw new InsufficientStockException(
                    "Insufficient stock. Available: {$stock->available_quantity}, Requested: {$quantity}."
                );
            }

            $beforeQuantity = $stock->quantity;

            $stock->decrement('quantity', $quantity);
            $stock->refresh();

            $movement = $this->recordMovement([
                'stockable_type' => get_class($stockable),
                'stockable_id' => $stockable->getKey(),
                'location_id' => $location->id,
                'transaction_id' => $transaction->id,
                'type' => $options->movementType ?? MovementType::Deduct,
                'quantity' => $quantity,
                'before_quantity' => $beforeQuantity,
                'after_quantity' => $stock->quantity,
                'reference_type' => $options->reference ? get_class($options->reference) : null,
                'reference_id' => $options->reference?->getKey(),
                'note' => $options->note,
                'created_by' => $options->createdBy,
            ]);

            $this->costing->linkSources($movement);

            if ($autoCreated) {
                $transaction->update(['status' => TransactionStatus::Committed]);
            }

            if ($this->shouldDispatch('StockDeducted')) {
                $this->events->dispatch(new StockDeducted($stockable, $stock, $movement, $quantity, $location));
            }

            $this->thresholds->evaluate($stockable, $stock, $location);

            return $stock;
        });
    }

    public function adjust(Model $stockable, int|float $newQuantity, Location $location, StockOperationDto $options = new StockOperationDto): Stock
    {
        return DB::transaction(function () use ($stockable, $newQuantity, $location, $options) {
            $stock = $this->findOrCreateStock($stockable, $location);
            $previousQuantity = $stock->quantity;

            if ($newQuantity == $previousQuantity) {
                return $stock;
            }

            [$transaction, $autoCreated] = $this->resolveOrCreateTransaction($options, TransactionType::Adjustment);

            $baseDto = new StockOperationDto(
                transaction: $transaction,
                transactionType: $options->transactionType,
                causable: $options->causable,
                reference: $options->reference,
                cost: $options->cost,
                note: $options->note,
                createdBy: $options->createdBy,
                allowNegative: true,
                expiresAt: $options->expiresAt,
            );

            if ($newQuantity > $previousQuantity) {
                $diff = $newQuantity - $previousQuantity;
                $stock = $this->add($stockable, $diff, $location, $baseDto->withMovementType(MovementType::AdjustmentIn));
            } else {
                $diff = $previousQuantity - $newQuantity;
                $stock = $this->deduct($stockable, $diff, $location, $baseDto->withMovementType(MovementType::AdjustmentOut));
            }

            $movement = Movement::where('transaction_id', $transaction->id)
                ->where('location_id', $location->id)
                ->latest('id')
                ->first();

            if ($autoCreated) {
                $transaction->update(['status' => TransactionStatus::Committed]);
            }

            if ($this->shouldDispatch('StockAdjusted') && $movement) {
                $this->events->dispatch(new StockAdjusted($stockable, $stock, $movement, $previousQuantity, $newQuantity, $location));
            }

            return $stock;
        });
    }
}
