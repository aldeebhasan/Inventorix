<?php

namespace Aldeebhasan\Inventorix\Services;

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
    protected function resolveOrCreateTransaction(array $options, TransactionType $defaultType, ?object $causable = null): array
    {
        if (isset($options['transaction'])) {
            return [$options['transaction'], false];
        }

        $resolvedCausable = $causable ?? $options['causable'] ?? null;

        $transaction = Transaction::create([
            'type' => $options['transaction_type'] ?? $defaultType,
            'status' => TransactionStatus::Pending,
            'causable_type' => $resolvedCausable ? get_class($resolvedCausable) : null,
            'causable_id' => $resolvedCausable ? $resolvedCausable->getKey() : null,
            'note' => $options['note'] ?? null,
            'created_by' => $options['created_by'] ?? null,
        ]);

        return [$transaction, true];
    }

    /**
     * Resolve the cost_per_unit to record on an inbound movement.
     *
     * Resolution order:
     *  1. Explicit `cost` key in $options (null means "no cost data").
     *  2. Stockable's cost_price attribute, only when strictly positive
     *     (zero-cost items intentionally produce null so the fallback path
     *     in ValuationService handles them uniformly).
     *  3. null — no cost information available.
     */
    protected function resolveCost(mixed $stockable, array $options): ?float
    {
        if (array_key_exists('cost', $options)) {
            return $options['cost'] !== null ? (float) $options['cost'] : null;
        }

        if (isset($stockable->cost_price)) {
            $cost = (float) $stockable->cost_price;

            return $cost > 0.0 ? $cost : null;
        }

        return null;
    }
}
