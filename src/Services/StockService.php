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
use Aldeebhasan\Inventorix\Support\HookRegistry;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class StockService extends BaseService implements StockServiceInterface
{
    public function __construct(
        private readonly Dispatcher $events,
        private readonly ThresholdServiceInterface $thresholds,
        private readonly CostingService $costing,
        private readonly SerialService $serials,
        private readonly HookRegistry $hooks,
    ) {}

    public function add(Model $stockable, int|float $quantity, Location $location, StockOperationDto $options = new StockOperationDto): Stock
    {
        if ($quantity <= 0) {
            throw new InvalidQuantityException;
        }

        return DB::transaction(function () use ($stockable, $quantity, $location, $options) {
            [$transaction, $autoCreated] = $this->resolveOrCreateTransaction($options, TransactionType::Manual);

            if (! $autoCreated && $transaction->status === TransactionStatus::Committed) {
                return $this->findOrCreateStock($stockable, $location, false);
            }

            $stock = $this->findOrCreateStock($stockable, $location);
            $beforeQuantity = $stock->quantity;

            $this->hooks->run('beforeAdd', $stockable, $location, $quantity, $options);

            $stock->increment('quantity', $quantity);
            $stock->refresh();

            $movement = $this->recordMovement([
                'stockable_type' => get_class($stockable),
                'stockable_id' => $stockable->getKey(),
                'location_id' => $location->id,
                'transaction_id' => $transaction->id,
                'type' => MovementType::Add,
                'quantity' => $quantity,
                'cost_per_unit' => $this->resolveCost($stockable, $options->cost),
                'before_quantity' => $beforeQuantity,
                'after_quantity' => $stock->quantity,
                'reference_type' => $options->reference ? get_class($options->reference) : null,
                'reference_id' => $options->reference?->getKey(),
                'note' => $options->note,
                'created_by' => $options->createdBy,
                'lot_reference' => $options->lotReference,
                'expires_at' => $options->expiresAt instanceof \DateTimeInterface ? $options->expiresAt->format('Y-m-d') : null,
                'external_reference' => $options->externalReference,
            ]);

            if (! $options->shouldSkipSerials()) {
                $this->serials->attach($movement, $stockable, $location, $quantity, $options->serials);
            }

            $this->hooks->run('afterAdd', $stock, $movement);

            if ($autoCreated) {
                $transaction->update(['status' => TransactionStatus::Committed]);
            }

            if ($this->shouldDispatch('StockAdded')) {
                $this->events->dispatch(new StockAdded($stockable, $stock, $location, $movement, $quantity, $options->causable, $options->externalReference));
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

            if (! $autoCreated && $transaction->status === TransactionStatus::Committed) {
                return $this->findOrCreateStock($stockable, $location, false);
            }

            $stock = $this->findOrCreateStock($stockable, $location);
            $allowNegative = $options->allowNegative || config('inventorix.allow_negative_stock', false);

            // When deducting from reserved stock (e.g. fulfillment), reserved_quantity is already
            // committed so we check total quantity instead of available_quantity.
            $effectiveAvailable = $options->isFromReservation() ? $stock->quantity : $stock->available_quantity;

            if (! $allowNegative && $effectiveAvailable < $quantity) {
                throw new InsufficientStockException(
                    "Insufficient stock. Available: {$stock->available_quantity}, Requested: {$quantity}."
                );
            }

            $beforeQuantity = $stock->quantity;

            $this->hooks->run('beforeDeduct', $stockable, $location, $quantity, $options);

            $stock->decrement('quantity', $quantity);
            $stock->refresh();

            $movement = $this->recordMovement([
                'stockable_type' => get_class($stockable),
                'stockable_id' => $stockable->getKey(),
                'location_id' => $location->id,
                'transaction_id' => $transaction->id,
                'type' => MovementType::Deduct,
                'quantity' => $quantity,
                'before_quantity' => $beforeQuantity,
                'after_quantity' => $stock->quantity,
                'reference_type' => $options->reference ? get_class($options->reference) : null,
                'reference_id' => $options->reference?->getKey(),
                'note' => $options->note,
                'created_by' => $options->createdBy,
                'external_reference' => $options->externalReference,
            ]);

            $this->costing->linkSources($movement);

            if (! $options->shouldSkipSerials()) {
                $this->serials->detach($movement, $stockable, $location, $quantity, $options->serials);
            }

            $this->hooks->run('afterDeduct', $stock, $movement);

            if ($autoCreated) {
                $transaction->update(['status' => TransactionStatus::Committed]);
            }

            if ($this->shouldDispatch('StockDeducted')) {
                $this->events->dispatch(new StockDeducted($stockable, $stock, $location, $movement, $quantity, $options->causable, $options->externalReference));
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
                $stock = $this->add($stockable, $diff, $location, $baseDto);
            } else {
                $diff = $previousQuantity - $newQuantity;
                $stock = $this->deduct($stockable, $diff, $location, $baseDto);
            }

            $movement = Movement::where('transaction_id', $transaction->id)
                ->where('location_id', $location->id)
                ->latest('id')
                ->first();

            if ($autoCreated) {
                $transaction->update(['status' => TransactionStatus::Committed]);
            }

            if ($this->shouldDispatch('StockAdjusted') && $movement) {
                $this->events->dispatch(new StockAdjusted($stockable, $stock, $location, $movement, $previousQuantity, $newQuantity, $options->causable, $options->externalReference));
            }

            return $stock;
        });
    }

    public function adjustByReference(Model $stockable, Model $reference, int|float $newQuantity, Location $location, StockOperationDto $options = new StockOperationDto): Stock
    {
        $movements = Movement::where('reference_type', get_class($reference))
            ->where('reference_id', $reference->getKey())
            ->where('stockable_type', get_class($stockable))
            ->where('stockable_id', $stockable->getKey())
            ->where('location_id', $location->id)
            ->get(['type', 'quantity']);

        $existingQty = $movements->sum(function (Movement $movement) {
            return $movement->type === MovementType::Add
                ? (float) $movement->quantity
                : -(float) $movement->quantity;
        });

        $delta = $newQuantity - $existingQty;

        if (abs($delta) < 0.0001) {
            return $this->findOrCreateStock($stockable, $location, false);
        }

        $correctionDto = new StockOperationDto(
            transaction: $options->transaction,
            transactionType: TransactionType::Adjustment,
            causable: $options->causable,
            reference: $reference,
            cost: $options->cost,
            note: sprintf('Correction for [%s#%s]: %s -> %s', class_basename($reference), $reference->getKey(), $existingQty, $newQuantity),
            createdBy: $options->createdBy,
            allowNegative: $options->allowNegative,
        );

        if ($delta > 0) {
            return $this->add($stockable, $delta, $location, $correctionDto);
        }

        return $this->deduct($stockable, abs($delta), $location, $correctionDto);
    }

    /**
     * Resolve the cost_per_unit to record on an inbound movement.
     *
     * Resolution order:
     *  2. Stockable's cost_price attribute, only when strictly positive.
     *  3. null — no cost information available.
     */
    private function resolveCost(mixed $stockable, float|int|null $cost): ?float
    {
        if (! is_null($cost)) {
            return $cost > 0.0 ? $cost : 0;
        }

        if (isset($stockable->cost_price)) {
            $cost = (float) $stockable->cost_price;

            return $cost > 0.0 ? $cost : 0;
        }

        return null;
    }
}
