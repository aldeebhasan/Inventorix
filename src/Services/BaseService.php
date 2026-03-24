<?php

namespace Aldeebhasan\Inventorix\Services;

use Aldeebhasan\Inventorix\DTOs\StockOperationDto;
use Aldeebhasan\Inventorix\Enums\TransactionStatus;
use Aldeebhasan\Inventorix\Enums\TransactionType;
use Aldeebhasan\Inventorix\Models\Location;
use Aldeebhasan\Inventorix\Models\Movement;
use Aldeebhasan\Inventorix\Models\Stock;
use Aldeebhasan\Inventorix\Models\Transaction;

abstract class BaseService
{
    protected function shouldDispatch(string $eventShortName): bool
    {
        if (! config('inventorix.events.enabled', true)) {
            return false;
        }

        $disabled = config('inventorix.events.disable', []);

        return ! in_array($eventShortName, $disabled, true);
    }

    protected function findOrCreateStock(mixed $stockable, Location $location): Stock
    {
        $attributes = [
            'stockable_type' => get_class($stockable),
            'stockable_id' => $stockable->getKey(),
            'location_id' => $location->id,
        ];

        $stock = Stock::where($attributes)->lockForUpdate()->first();

        if (! $stock) {
            $stock = Stock::create(array_merge($attributes, [
                'quantity' => 0,
                'reserved_quantity' => 0,
            ]));
        }

        return $stock;
    }

    protected function recordMovement(array $data): Movement
    {
        return Movement::create($data);
    }

    /**
     * Return the provided transaction or auto-create one.
     * Returns [$transaction, $wasAutoCreated].
     */
    protected function resolveOrCreateTransaction(StockOperationDto $options, TransactionType $defaultType): array
    {
        if ($options->transaction !== null) {
            return [$options->transaction, false];
        }

        $transaction = Transaction::create([
            'type' => $options->transactionType ?? $defaultType,
            'status' => TransactionStatus::Pending,
            'causable_type' => $options->causable ? get_class($options->causable) : null,
            'causable_id' => $options->causable ? $options->causable->getKey() : null,
            'note' => $options->note,
            'created_by' => $options->createdBy,
        ]);

        return [$transaction, true];
    }

    /**
     * Resolve the cost_per_unit to record on an inbound movement.
     *
     * Resolution order:
     *  1. $options->cost !== false  → explicit value provided (null means "no cost").
     *  2. Stockable's cost_price attribute, only when strictly positive.
     *  3. null — no cost information available.
     */
    protected function resolveCost(mixed $stockable, StockOperationDto $options): ?float
    {
        if ($options->cost !== false) {
            return $options->cost !== null ? (float) $options->cost : null;
        }

        if (isset($stockable->cost_price)) {
            $cost = (float) $stockable->cost_price;

            return $cost > 0.0 ? $cost : null;
        }

        return null;
    }
}
