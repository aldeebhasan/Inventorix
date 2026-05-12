<?php

namespace Aldeebhasan\Inventorix\Services;

use Aldeebhasan\Inventorix\DTOs\StockOperationDto;
use Aldeebhasan\Inventorix\Enums\TransactionStatus;
use Aldeebhasan\Inventorix\Enums\TransactionType;
use Aldeebhasan\Inventorix\Models\Location;
use Aldeebhasan\Inventorix\Models\Movement;
use Aldeebhasan\Inventorix\Models\Stock;
use Aldeebhasan\Inventorix\Models\Transaction;
use Aldeebhasan\Inventorix\Support\LockRetry;

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

    protected function findOrCreateStock(mixed $stockable, Location $location, $lock = true): Stock
    {
        $attributes = [
            'stockable_type' => get_class($stockable),
            'stockable_id' => $stockable->getKey(),
            'location_id' => $location->id,
        ];

        return LockRetry::run(function () use ($attributes, $lock) {
            $stock = Stock::where($attributes)->when($lock, fn ($q) => $q->lockForUpdate())->first();

            if (! $stock) {
                $stock = Stock::create(array_merge($attributes, [
                    'quantity' => 0,
                    'reserved_quantity' => 0,
                ]));
            }

            return $stock;
        });
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
     * Recompute cost_per_unit for a deduction from its current movement_sources and
     * persist the result on the movement. Useful after correcting a source lot's cost.
     * Returns the new cost, or null when no sources exist.
     */
    protected function computeDeductionCost(Movement $movement): ?float
    {
        return app(CostingService::class)->recomputeAndStore($movement);
    }
}
