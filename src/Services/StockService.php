<?php

namespace Aldeebhasan\Inventorix\Services;

use Aldeebhasan\Inventorix\Contracts\StockServiceInterface;
use Aldeebhasan\Inventorix\Contracts\ThresholdServiceInterface;
use Aldeebhasan\Inventorix\Enums\MovementType;
use Aldeebhasan\Inventorix\Events\StockAdded;
use Aldeebhasan\Inventorix\Events\StockAdjusted;
use Aldeebhasan\Inventorix\Events\StockDeducted;
use Aldeebhasan\Inventorix\Exceptions\InsufficientStockException;
use Aldeebhasan\Inventorix\Exceptions\InvalidQuantityException;
use Aldeebhasan\Inventorix\Models\Location;
use Aldeebhasan\Inventorix\Models\Stock;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class StockService extends BaseService implements StockServiceInterface
{
    public function __construct(
        private readonly Dispatcher $events,
        private readonly ThresholdServiceInterface $thresholds
    ) {}

    public function add(Model $stockable, int|float $quantity, Location $location, array $options = []): Stock
    {
        if ($quantity <= 0) {
            throw new InvalidQuantityException;
        }

        return DB::transaction(function () use ($stockable, $quantity, $location, $options) {
            $stock = $this->findOrCreateStock($stockable, $location);
            $beforeQuantity = $stock->quantity;

            $stock->increment('quantity', $quantity);
            $stock->refresh();

            $movement = $this->recordMovement([
                'stockable_type' => get_class($stockable),
                'stockable_id' => $stockable->getKey(),
                'location_id' => $location->id,
                'transaction_id' => isset($options['transaction']) ? $options['transaction']->id : null,
                'type' => MovementType::Add,
                'quantity' => $quantity,
                'cost_per_unit' => $this->resolveCost($stockable, $options),
                'before_quantity' => $beforeQuantity,
                'after_quantity' => $stock->quantity,
                'reference_type' => isset($options['reference']) ? get_class($options['reference']) : null,
                'reference_id' => isset($options['reference']) ? $options['reference']->getKey() : null,
                'note' => $options['note'] ?? null,
                'created_by' => $options['created_by'] ?? null,
            ]);

            if ($this->shouldDispatch('StockAdded')) {
                $this->events->dispatch(new StockAdded($stockable, $stock, $movement, $quantity, $location));
            }

            $this->thresholds->evaluate($stockable, $stock, $location);

            return $stock;
        });
    }

    public function deduct(Model $stockable, int|float $quantity, Location $location, array $options = []): Stock
    {
        if ($quantity <= 0) {
            throw new InvalidQuantityException;
        }

        return DB::transaction(function () use ($stockable, $quantity, $location, $options) {
            $stock = $this->findOrCreateStock($stockable, $location);
            $allowNegative = $options['allow_negative'] ?? config('inventorix.allow_negative_stock', false);

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
                'transaction_id' => isset($options['transaction']) ? $options['transaction']->id : null,
                'type' => MovementType::Deduct,
                'quantity' => $quantity,
                'before_quantity' => $beforeQuantity,
                'after_quantity' => $stock->quantity,
                'reference_type' => isset($options['reference']) ? get_class($options['reference']) : null,
                'reference_id' => isset($options['reference']) ? $options['reference']->getKey() : null,
                'note' => $options['note'] ?? null,
                'created_by' => $options['created_by'] ?? null,
            ]);

            if ($this->shouldDispatch('StockDeducted')) {
                $this->events->dispatch(new StockDeducted($stockable, $stock, $movement, $quantity, $location));
            }

            $this->thresholds->evaluate($stockable, $stock, $location);

            return $stock;
        });
    }

    public function adjust(Model $stockable, int|float $newQuantity, Location $location, array $options = []): Stock
    {
        return DB::transaction(function () use ($stockable, $newQuantity, $location, $options) {
            $stock = $this->findOrCreateStock($stockable, $location);
            $previousQuantity = $stock->quantity;
            $delta = $newQuantity - $previousQuantity;

            $stock->update(['quantity' => $newQuantity]);
            $stock->refresh();

            $movement = $this->recordMovement([
                'stockable_type' => get_class($stockable),
                'stockable_id' => $stockable->getKey(),
                'location_id' => $location->id,
                'transaction_id' => isset($options['transaction']) ? $options['transaction']->id : null,
                'type' => MovementType::Adjustment,
                'quantity' => $delta,
                'cost_per_unit' => $delta > 0 ? $this->resolveCost($stockable, $options) : null,
                'before_quantity' => $previousQuantity,
                'after_quantity' => $newQuantity,
                'note' => $options['note'] ?? $options['reason'] ?? null,
                'created_by' => $options['created_by'] ?? null,
            ]);

            if ($this->shouldDispatch('StockAdjusted')) {
                $this->events->dispatch(new StockAdjusted($stockable, $stock, $movement, $previousQuantity, $newQuantity, $location));
            }

            $this->thresholds->evaluate($stockable, $stock, $location);

            return $stock;
        });
    }
}
