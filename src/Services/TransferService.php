<?php

namespace Aldeebhasan\Inventorix\Services;

use Aldeebhasan\Inventorix\Contracts\TransferServiceInterface;
use Aldeebhasan\Inventorix\Enums\MovementType;
use Aldeebhasan\Inventorix\Enums\TransactionStatus;
use Aldeebhasan\Inventorix\Enums\TransactionType;
use Aldeebhasan\Inventorix\Events\StockTransferred;
use Aldeebhasan\Inventorix\Exceptions\InsufficientStockException;
use Aldeebhasan\Inventorix\Exceptions\InvalidQuantityException;
use Aldeebhasan\Inventorix\Models\Location;
use Aldeebhasan\Inventorix\Models\Movement;
use Aldeebhasan\Inventorix\Models\Stock;
use Aldeebhasan\Inventorix\Models\Transaction;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class TransferService implements TransferServiceInterface
{
    public function __construct(private readonly Dispatcher $events) {}

    private function shouldDispatch(string $eventShortName): bool
    {
        if (! config('inventorix.events.enabled', true)) {
            return false;
        }

        $disabled = config('inventorix.events.disable', []);

        return ! in_array($eventShortName, $disabled, true);
    }

    private function findOrCreateStock(mixed $stockable, Location $location): Stock
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

    private function recordMovement(array $data): Movement
    {
        return Movement::create($data);
    }

    public function transfer(Model $stockable, int|float $quantity, Location $from, Location $to, array $options = []): bool
    {
        if ($quantity <= 0) {
            throw new InvalidQuantityException;
        }

        DB::transaction(function () use ($stockable, $quantity, $from, $to, $options) {
            $transaction = Transaction::create([
                'type' => TransactionType::Transfer,
                'status' => TransactionStatus::Pending,
                'note' => $options['note'] ?? null,
                'created_by' => $options['created_by'] ?? null,
            ]);

            $fromStock = $this->findOrCreateStock($stockable, $from);
            $allowNegative = $options['allow_negative'] ?? config('inventorix.allow_negative_stock', false);

            if (! $allowNegative && $fromStock->available_quantity < $quantity) {
                throw new InsufficientStockException(
                    "Insufficient stock at source. Available: {$fromStock->available_quantity}, Requested: {$quantity}."
                );
            }

            $beforeFrom = $fromStock->quantity;
            $fromStock->decrement('quantity', $quantity);
            $fromStock->refresh();

            $this->recordMovement([
                'stockable_type' => get_class($stockable),
                'stockable_id' => $stockable->getKey(),
                'location_id' => $from->id,
                'transaction_id' => $transaction->id,
                'type' => MovementType::TransferOut,
                'quantity' => $quantity,
                'before_quantity' => $beforeFrom,
                'after_quantity' => $fromStock->quantity,
                'reference_type' => isset($options['reference']) ? get_class($options['reference']) : null,
                'reference_id' => isset($options['reference']) ? $options['reference']->getKey() : null,
                'note' => $options['note'] ?? null,
                'created_by' => $options['created_by'] ?? null,
            ]);

            $toStock = $this->findOrCreateStock($stockable, $to);
            $beforeTo = $toStock->quantity;
            $toStock->increment('quantity', $quantity);
            $toStock->refresh();

            $this->recordMovement([
                'stockable_type' => get_class($stockable),
                'stockable_id' => $stockable->getKey(),
                'location_id' => $to->id,
                'transaction_id' => $transaction->id,
                'type' => MovementType::TransferIn,
                'quantity' => $quantity,
                'before_quantity' => $beforeTo,
                'after_quantity' => $toStock->quantity,
                'reference_type' => isset($options['reference']) ? get_class($options['reference']) : null,
                'reference_id' => isset($options['reference']) ? $options['reference']->getKey() : null,
                'note' => $options['note'] ?? null,
                'created_by' => $options['created_by'] ?? null,
            ]);

            $transaction->update(['status' => TransactionStatus::Committed]);

            if ($this->shouldDispatch('StockTransferred')) {
                $this->events->dispatch(new StockTransferred($stockable, $quantity, $from, $to, $transaction));
            }
        });

        return true;
    }
}
