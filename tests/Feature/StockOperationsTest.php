<?php

use Aldeebhasan\Inventorix\Enums\MovementType;
use Aldeebhasan\Inventorix\Enums\TransactionStatus;
use Aldeebhasan\Inventorix\Exceptions\InsufficientStockException;
use Aldeebhasan\Inventorix\Exceptions\InvalidQuantityException;
use Aldeebhasan\Inventorix\Facades\Inventorix;
use Aldeebhasan\Inventorix\Models\Location;
use Aldeebhasan\Inventorix\Models\Movement;
use Aldeebhasan\Inventorix\Models\Transaction;
use Aldeebhasan\Inventorix\Tests\Support\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->location = Location::create(['name' => 'Warehouse A', 'code' => 'WH-A', 'is_active' => true]);
    $this->product = Product::create(['name' => 'Widget', 'cost_price' => 10.00]);
});

it('addStock increases quantity correctly', function () {
    $stock = $this->product->addStock(50, $this->location);

    expect($stock->quantity)->toEqual(50)
        ->and($stock->location_id)->toBe($this->location->id);
});

it('addStock throws InvalidQuantityException for qty <= 0', function () {
    $this->product->addStock(0, $this->location);
})->throws(InvalidQuantityException::class);

it('addStock throws InvalidQuantityException for negative qty', function () {
    $this->product->addStock(-5, $this->location);
})->throws(InvalidQuantityException::class);

it('deductStock decreases quantity correctly', function () {
    $this->product->addStock(100, $this->location);
    $stock = $this->product->deductStock(30, $this->location);

    expect($stock->quantity)->toEqual(70);
});

it('deductStock throws InsufficientStockException when insufficient', function () {
    $this->product->addStock(20, $this->location);
    $this->product->deductStock(50, $this->location);
})->throws(InsufficientStockException::class);

it('deductStock allows negative when allow_negative=true', function () {
    $this->product->addStock(10, $this->location);
    $stock = $this->product->deductStock(20, $this->location, ['allow_negative' => true]);

    expect($stock->quantity)->toEqual(-10);
});

it('transferStock moves stock between locations', function () {
    $locationB = Location::create(['name' => 'Warehouse B', 'code' => 'WH-B', 'is_active' => true]);

    $this->product->addStock(100, $this->location);
    $this->product->transferStock(40, $this->location, $locationB);

    $stockA = $this->product->stockAt($this->location);
    $stockB = $this->product->stockAt($locationB);

    expect($stockA->quantity)->toEqual(60)
        ->and($stockB->quantity)->toEqual(40);
});

it('adjustStock sets absolute quantity (increase)', function () {
    $this->product->addStock(10, $this->location);
    $stock = $this->product->adjustStock(50, $this->location);

    expect($stock->quantity)->toEqual(50);
});

it('adjustStock sets absolute quantity (decrease)', function () {
    $this->product->addStock(100, $this->location);
    $stock = $this->product->adjustStock(30, $this->location);

    expect($stock->quantity)->toEqual(30);
});

it('addStock creates a Movement record of correct type', function () {
    $this->product->addStock(25, $this->location);

    $movement = Movement::where('stockable_type', Product::class)
        ->where('stockable_id', $this->product->id)
        ->where('location_id', $this->location->id)
        ->first();

    expect($movement)->not->toBeNull()
        ->and($movement->type)->toBe(MovementType::Add)
        ->and($movement->quantity)->toEqual(25);
});

it('bulk operation groups movements in same Transaction', function () {
    $product2 = Product::create(['name' => 'Gadget', 'cost_price' => 5.00]);

    $transaction = Inventorix::bulk(function (Transaction $tx) use ($product2) {
        Inventorix::addStock($this->product, 100, $this->location, ['transaction' => $tx]);
        Inventorix::addStock($product2, 50, $this->location, ['transaction' => $tx]);
    });

    expect($transaction->status)->toBe(TransactionStatus::Committed);

    $movements = Movement::where('transaction_id', $transaction->id)->get();
    expect($movements->count())->toBe(2);
});

it('movements have correct before/after quantities', function () {
    $this->product->addStock(100, $this->location);
    $this->product->deductStock(30, $this->location);

    $movements = Movement::where('stockable_type', Product::class)
        ->where('stockable_id', $this->product->id)
        ->orderBy('id')
        ->get();

    expect($movements[0]->before_quantity)->toEqual(0)
        ->and($movements[0]->after_quantity)->toEqual(100)
        ->and($movements[1]->before_quantity)->toEqual(100)
        ->and($movements[1]->after_quantity)->toEqual(70);
});
