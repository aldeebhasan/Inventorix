<?php

use Aldeebhasan\Inventorix\DTOs\StockOperationDto;
use Aldeebhasan\Inventorix\Enums\TransactionStatus;
use Aldeebhasan\Inventorix\Enums\TransactionType;
use Aldeebhasan\Inventorix\Exceptions\InsufficientStockException;
use Aldeebhasan\Inventorix\Facades\Inventorix;
use Aldeebhasan\Inventorix\Models\Location;
use Aldeebhasan\Inventorix\Models\Movement;
use Aldeebhasan\Inventorix\Models\Transaction;
use Aldeebhasan\Inventorix\Tests\Support\Order;
use Aldeebhasan\Inventorix\Tests\Support\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->location = Location::create(['name' => 'Warehouse A', 'code' => 'WH-A', 'is_active' => true]);
    $this->product = Product::create(['name' => 'Widget', 'cost_price' => 10.00]);
    $this->order = Order::create(['number' => 'INV-001']);
});

// ---------------------------------------------------------------------------
// Layer 2 - transaction-level idempotency via existing fields
// ---------------------------------------------------------------------------

it('Purchase + causable: second call returns same committed transaction and does not mutate stock', function () {
    $dto = new StockOperationDto(transactionType: TransactionType::Purchase, causable: $this->order);

    $stock1 = $this->product->addStock(10, $this->location, $dto);
    $stock2 = $this->product->addStock(10, $this->location, $dto);

    expect((float) $stock2->quantity)->toBe(10.0)
        ->and(Transaction::count())->toBe(1);
});

it('Purchase + causable: existing Pending transaction is not reused', function () {
    // Simulate a crashed first attempt: Pending transaction exists but never committed
    Transaction::create([
        'type' => TransactionType::Purchase,
        'status' => TransactionStatus::Pending,
        'causable_type' => get_class($this->order),
        'causable_id' => $this->order->id,
    ]);

    $this->product->addStock(10, $this->location, new StockOperationDto(
        transactionType: TransactionType::Purchase,
        causable: $this->order,
    ));

    expect(Transaction::count())->toBe(2);
});

it('Purchase + causable: existing RolledBack transaction is not reused', function () {
    Transaction::create([
        'type' => TransactionType::Purchase,
        'status' => TransactionStatus::RolledBack,
        'causable_type' => get_class($this->order),
        'causable_id' => $this->order->id,
    ]);

    $this->product->addStock(10, $this->location, new StockOperationDto(
        transactionType: TransactionType::Purchase,
        causable: $this->order,
    ));

    expect(Transaction::count())->toBe(2);
});

it('Purchase without causable: always creates a new transaction', function () {
    $this->product->addStock(10, $this->location, new StockOperationDto(transactionType: TransactionType::Purchase));
    $this->product->addStock(10, $this->location, new StockOperationDto(transactionType: TransactionType::Purchase));

    expect(Transaction::count())->toBe(2);
});

it('Adjustment + causable: always creates a new transaction (repeatable)', function () {
    $dto = new StockOperationDto(transactionType: TransactionType::Adjustment, causable: $this->order);

    $this->product->addStock(10, $this->location, $dto);
    $this->product->addStock(5, $this->location, $dto);

    expect(Transaction::count())->toBe(2);
    expect((float) $this->product->addStock(0.001, $this->location, $dto)->quantity)->toBeGreaterThan(15.0);
})->skip('Adjustment dedup disabled by design - this documents expected behaviour');

it('Adjustment + causable: second call creates a new transaction not reusing the first', function () {
    $this->product->addStock(10, $this->location, new StockOperationDto(
        transactionType: TransactionType::Adjustment,
        causable: $this->order,
    ));
    $this->product->addStock(5, $this->location, new StockOperationDto(
        transactionType: TransactionType::Adjustment,
        causable: $this->order,
    ));

    expect(Transaction::count())->toBe(2);
    expect((float) $this->product->stocks()->where('location_id', $this->location->id)->value('quantity'))->toBe(15.0);
});

it('Purchase + different causable: creates two distinct transactions', function () {
    $otherOrder = Order::create(['number' => 'INV-002']);

    $this->product->addStock(10, $this->location, new StockOperationDto(
        transactionType: TransactionType::Purchase,
        causable: $this->order,
    ));
    $this->product->addStock(10, $this->location, new StockOperationDto(
        transactionType: TransactionType::Purchase,
        causable: $otherOrder,
    ));

    expect(Transaction::count())->toBe(2);
    expect((float) $this->product->stocks()->where('location_id', $this->location->id)->value('quantity'))->toBe(20.0);
});

// ---------------------------------------------------------------------------
// Layer 3 - adjustByReference()
// ---------------------------------------------------------------------------

it('adjustByReference on non-existent ref behaves as initial add', function () {
    $stock = Inventorix::adjustByReference($this->product, $this->order, 10, $this->location);

    expect((float) $stock->quantity)->toBe(10.0);

    $count = Movement::where('stockable_type', get_class($this->product))
        ->where('stockable_id', $this->product->id)
        ->where('location_id', $this->location->id)
        ->count();
    expect($count)->toBe(1);
});

it('adjustByReference increase applies only the delta', function () {
    $this->product->addStock(10, $this->location, new StockOperationDto(reference: $this->order));

    $stock = Inventorix::adjustByReference($this->product, $this->order, 15, $this->location);

    expect((float) $stock->quantity)->toBe(15.0);

    $count = Movement::where('stockable_type', get_class($this->product))
        ->where('stockable_id', $this->product->id)
        ->where('location_id', $this->location->id)
        ->count();
    expect($count)->toBe(2);
});

it('adjustByReference decrease applies only the delta', function () {
    $this->product->addStock(10, $this->location, new StockOperationDto(reference: $this->order));

    $stock = Inventorix::adjustByReference($this->product, $this->order, 7, $this->location);

    expect((float) $stock->quantity)->toBe(7.0);

    $count = Movement::where('stockable_type', get_class($this->product))
        ->where('stockable_id', $this->product->id)
        ->where('location_id', $this->location->id)
        ->count();
    expect($count)->toBe(2);
});

it('adjustByReference with same quantity is a no-op', function () {
    $this->product->addStock(10, $this->location, new StockOperationDto(reference: $this->order));

    $stock = Inventorix::adjustByReference($this->product, $this->order, 10, $this->location);

    expect((float) $stock->quantity)->toBe(10.0);

    $count = Movement::where('stockable_type', get_class($this->product))
        ->where('stockable_id', $this->product->id)
        ->where('location_id', $this->location->id)
        ->count();
    expect($count)->toBe(1);
});

it('adjustByReference decrease propagates InsufficientStockException', function () {
    $this->product->addStock(5, $this->location, new StockOperationDto(reference: $this->order));

    Inventorix::adjustByReference($this->product, $this->order, -10, $this->location);
})->throws(InsufficientStockException::class);

it('adjustByReference via HasInventory trait works correctly', function () {
    $this->product->addStock(10, $this->location, new StockOperationDto(reference: $this->order));

    $stock = $this->product->adjustStockByReference($this->order, 20, $this->location);

    expect((float) $stock->quantity)->toBe(20.0);
});

it('adjustByReference corrective movement carries the same reference and a descriptive note', function () {
    $this->product->addStock(10, $this->location, new StockOperationDto(reference: $this->order));

    Inventorix::adjustByReference($this->product, $this->order, 15, $this->location);

    $correction = Movement::where('reference_type', get_class($this->order))
        ->where('reference_id', $this->order->id)
        ->where('stockable_type', get_class($this->product))
        ->orderByDesc('id')
        ->first();

    expect($correction->note)->toContain('Order')
        ->and($correction->note)->toContain((string) $this->order->id);
});

it('adjustByReference scopes to the given reference - different order is not a duplicate', function () {
    $otherOrder = Order::create(['number' => 'INV-002']);

    $this->product->addStock(10, $this->location, new StockOperationDto(reference: $this->order));
    $this->product->addStock(5, $this->location, new StockOperationDto(reference: $otherOrder));

    // Only INV-001 movements (10 units) are considered; delta = 15 - 10 = 5
    $stock = Inventorix::adjustByReference($this->product, $this->order, 15, $this->location);

    expect((float) $stock->quantity)->toBe(20.0); // 10 (INV-001) + 5 (INV-002) + 5 (correction)
});
