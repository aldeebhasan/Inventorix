<?php

use Aldeebhasan\Inventorix\Enums\TransactionStatus;
use Aldeebhasan\Inventorix\Enums\TransactionType;
use Aldeebhasan\Inventorix\Exceptions\InsufficientStockException;
use Aldeebhasan\Inventorix\Exceptions\InvalidQuantityException;
use Aldeebhasan\Inventorix\Models\Location;
use Aldeebhasan\Inventorix\Models\Movement;
use Aldeebhasan\Inventorix\Tests\Support\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->locationA = Location::create(['name' => 'Warehouse A', 'code' => 'WH-A', 'is_active' => true]);
    $this->locationB = Location::create(['name' => 'Warehouse B', 'code' => 'WH-B', 'is_active' => true]);
    $this->product = Product::create(['name' => 'Widget', 'cost_price' => 10.00]);
    $this->product->addStock(100, $this->locationA);
});

it('send deducts stock and returns a pending transaction', function () {
    $transaction = $this->product->sendStock(50, $this->locationA);

    expect($transaction->status)->toBe(TransactionStatus::Pending)
        ->and($transaction->type)->toBe(TransactionType::Transfer)
        ->and($this->product->stockAt($this->locationA)->quantity)->toEqual(50);
});

it('receive adds stock and commits the transaction', function () {
    $transaction = $this->product->sendStock(50, $this->locationA);

    $this->product->receiveStock($transaction, 50, $this->locationB);

    $transaction->refresh();

    expect($transaction->status)->toBe(TransactionStatus::Committed)
        ->and($this->product->stockAt($this->locationA)->quantity)->toEqual(50)
        ->and($this->product->stockAt($this->locationB)->quantity)->toEqual(50);
});

it('send and receive produce movements under the same transaction', function () {
    $transaction = $this->product->sendStock(40, $this->locationA);
    $this->product->receiveStock($transaction, 40, $this->locationB);

    $movements = Movement::where('transaction_id', $transaction->id)->get();

    expect($movements)->toHaveCount(2);
});

it('send throws InvalidQuantityException for qty <= 0', function () {
    $this->product->sendStock(0, $this->locationA);
})->throws(InvalidQuantityException::class);

it('receive throws InvalidQuantityException for qty <= 0', function () {
    $transaction = $this->product->sendStock(10, $this->locationA);
    $this->product->receiveStock($transaction, 0, $this->locationB);
})->throws(InvalidQuantityException::class);

it('send throws InsufficientStockException when insufficient', function () {
    $this->product->sendStock(200, $this->locationA);
})->throws(InsufficientStockException::class);
