<?php

use Aldeebhasan\Inventorix\Models\Location;
use Aldeebhasan\Inventorix\Models\Stock;
use Aldeebhasan\Inventorix\Tests\Support\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->locationA = Location::create(['name' => 'Warehouse A', 'code' => 'WH-A', 'is_active' => true]);
    $this->locationB = Location::create(['name' => 'Warehouse B', 'code' => 'WH-B', 'is_active' => true]);
    $this->product = Product::create(['name' => 'Widget', 'cost_price' => 10.00]);
});

it('stockAt returns correct Stock model', function () {
    $this->product->addStock(50, $this->locationA);

    $stock = $this->product->stockAt($this->locationA);

    expect($stock)->toBeInstanceOf(Stock::class)
        ->and($stock->quantity)->toEqual(50)
        ->and($stock->location_id)->toBe($this->locationA->id);
});

it('stockAt returns null for unknown location', function () {
    $stock = $this->product->stockAt($this->locationA);

    expect($stock)->toBeNull();
});

it('totalStock sums across locations', function () {
    $this->product->addStock(50, $this->locationA);
    $this->product->addStock(30, $this->locationB);

    expect($this->product->totalStock())->toEqual(80);
});

it('availableStock returns quantity minus reserved_quantity', function () {
    $this->product->addStock(100, $this->locationA);
    $this->product->reserve(20, $this->locationA);

    expect($this->product->availableStock($this->locationA))->toEqual(80);
});

it('availableStock sums across all locations when no location given', function () {
    $this->product->addStock(100, $this->locationA);
    $this->product->addStock(50, $this->locationB);
    $this->product->reserve(20, $this->locationA);

    expect($this->product->availableStock())->toEqual(130);
});

it('movementHistory filters by type', function () {
    $this->product->addStock(100, $this->locationA);
    $this->product->deductStock(30, $this->locationA);

    $addMovements = $this->product->movementHistory(type: 'add')->get();
    $deductMovements = $this->product->movementHistory(type: 'deduct')->get();

    expect($addMovements->count())->toBe(1)
        ->and($deductMovements->count())->toBe(1);
});

it('movementHistory filters by location', function () {
    $this->product->addStock(100, $this->locationA);
    $this->product->addStock(50, $this->locationB);

    $movementsA = $this->product->movementHistory(location: $this->locationA)->get();

    expect($movementsA->count())->toBe(1)
        ->and($movementsA->first()->location_id)->toBe($this->locationA->id);
});

it('stockSummary returns correct structure', function () {
    $this->product->addStock(50, $this->locationA);
    $this->product->addStock(30, $this->locationB);
    $this->product->reserve(10, $this->locationA);

    $summary = $this->product->stockSummary();

    expect($summary)->toHaveKeys(['total_quantity', 'reserved_quantity', 'available_quantity', 'locations', 'is_low_stock', 'last_movement_at'])
        ->and($summary['total_quantity'])->toEqual(80)
        ->and($summary['reserved_quantity'])->toEqual(10)
        ->and($summary['available_quantity'])->toEqual(70)
        ->and($summary['locations'])->toHaveCount(2);
});
