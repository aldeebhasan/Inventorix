<?php

use Aldeebhasan\Inventorix\DTOs\StockOperationDto;
use Aldeebhasan\Inventorix\Enums\MovementType;
use Aldeebhasan\Inventorix\Enums\TransactionStatus;
use Aldeebhasan\Inventorix\Enums\TransactionType;
use Aldeebhasan\Inventorix\Events\TransactionRolledBack;
use Aldeebhasan\Inventorix\Exceptions\TransactionAlreadyRolledBackException;
use Aldeebhasan\Inventorix\Facades\Inventorix;
use Aldeebhasan\Inventorix\Models\Location;
use Aldeebhasan\Inventorix\Models\Movement;
use Aldeebhasan\Inventorix\Models\Transaction;
use Aldeebhasan\Inventorix\Tests\Support\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->location = Location::create(['name' => 'Warehouse A', 'code' => 'WH-A', 'is_active' => true]);
    $this->product = Product::create(['name' => 'Widget', 'cost_price' => 10.00]);
});

it('rolls back an addStock transaction and restores stock quantity to zero', function () {
    $transaction = Inventorix::bulk(function (Transaction $tx) {
        $this->product->addStock(50, $this->location, new StockOperationDto(transaction: $tx));
    });

    expect($this->product->totalStock($this->location))->toEqual(50);

    Inventorix::rollback($transaction);

    expect($this->product->totalStock($this->location))->toEqual(0);
});

it('rolls back a deductStock transaction and restores the stock quantity', function () {
    $this->product->addStock(100, $this->location);

    $transaction = Inventorix::bulk(function (Transaction $tx) {
        $this->product->deductStock(30, $this->location, new StockOperationDto(transaction: $tx));
    });

    expect($this->product->totalStock($this->location))->toEqual(70);

    Inventorix::rollback($transaction);

    expect($this->product->totalStock($this->location))->toEqual(100);
});

it('marks the original transaction as RolledBack and sets reversed_at', function () {
    $transaction = Inventorix::bulk(function (Transaction $tx) {
        $this->product->addStock(10, $this->location, new StockOperationDto(transaction: $tx));
    });

    Inventorix::rollback($transaction);

    $transaction->refresh();

    expect($transaction->status)->toBe(TransactionStatus::RolledBack)
        ->and($transaction->reversed_at)->not->toBeNull()
        ->and($transaction->reversed_by_transaction_id)->not->toBeNull();
});

it('creates a reversal transaction of type Reversal with status Committed', function () {
    $transaction = Inventorix::bulk(function (Transaction $tx) {
        $this->product->addStock(25, $this->location, new StockOperationDto(transaction: $tx));
    });

    $reversal = Inventorix::rollback($transaction);

    expect($reversal->type)->toBe(TransactionType::Reversal)
        ->and($reversal->status)->toBe(TransactionStatus::Committed);
});

it('links the original transaction to the reversal transaction', function () {
    $transaction = Inventorix::bulk(function (Transaction $tx) {
        $this->product->addStock(25, $this->location, new StockOperationDto(transaction: $tx));
    });

    $reversal = Inventorix::rollback($transaction);

    $transaction->refresh();

    expect($transaction->reversed_by_transaction_id)->toBe($reversal->id);
});

it('creates a compensation Deduct movement for each original Add movement', function () {
    $transaction = Inventorix::bulk(function (Transaction $tx) {
        $this->product->addStock(40, $this->location, new StockOperationDto(transaction: $tx));
    });

    $reversal = Inventorix::rollback($transaction);

    $reversalMovements = Movement::where('transaction_id', $reversal->id)->get();

    expect($reversalMovements)->toHaveCount(1)
        ->and($reversalMovements->first()->type)->toBe(MovementType::Deduct)
        ->and((float) $reversalMovements->first()->quantity)->toEqual(40.0);
});

it('fires the TransactionRolledBack event after commit', function () {
    Event::fake([TransactionRolledBack::class]);

    $transaction = Inventorix::bulk(function (Transaction $tx) {
        $this->product->addStock(10, $this->location, new StockOperationDto(transaction: $tx));
    });

    $reversal = Inventorix::rollback($transaction);

    Event::assertDispatched(TransactionRolledBack::class, function (TransactionRolledBack $event) use ($transaction, $reversal) {
        return $event->originalTransaction->id === $transaction->id
            && $event->reversalTransaction->id === $reversal->id;
    });
});

it('throws TransactionAlreadyRolledBackException when rolling back twice', function () {
    $transaction = Inventorix::bulk(function (Transaction $tx) {
        $this->product->addStock(10, $this->location, new StockOperationDto(transaction: $tx));
    });

    Inventorix::rollback($transaction);
    $transaction->refresh();

    Inventorix::rollback($transaction);
})->throws(TransactionAlreadyRolledBackException::class);

it('rolls back a multi-movement bulk transaction and restores all quantities', function () {
    $locationB = Location::create(['name' => 'Warehouse B', 'code' => 'WH-B', 'is_active' => true]);
    $productB = Product::create(['name' => 'Gadget', 'cost_price' => 5.00]);

    $this->product->addStock(200, $this->location);
    $productB->addStock(100, $locationB);

    $transaction = Inventorix::bulk(function (Transaction $tx) use ($locationB, $productB) {
        $dto = new StockOperationDto(transaction: $tx);
        $this->product->deductStock(50, $this->location, $dto);
        $productB->deductStock(30, $locationB, $dto);
    });

    expect($this->product->totalStock($this->location))->toEqual(150)
        ->and($productB->totalStock($locationB))->toEqual(70);

    Inventorix::rollback($transaction);

    expect($this->product->totalStock($this->location))->toEqual(200)
        ->and($productB->totalStock($locationB))->toEqual(100);
});

it('preserves the audit trail — original movements are not deleted after rollback', function () {
    $transaction = Inventorix::bulk(function (Transaction $tx) {
        $this->product->addStock(50, $this->location, new StockOperationDto(transaction: $tx));
    });

    $originalMovementCount = Movement::where('transaction_id', $transaction->id)->count();

    Inventorix::rollback($transaction);

    expect(Movement::where('transaction_id', $transaction->id)->count())->toBe($originalMovementCount);
});
