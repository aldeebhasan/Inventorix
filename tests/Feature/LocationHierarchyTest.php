<?php

use Aldeebhasan\Inventorix\Models\Location;
use Aldeebhasan\Inventorix\Models\Stock;
use Aldeebhasan\Inventorix\Tests\Support\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Warehouse (root)
    //   └── Zone A
    //         └── Shelf A1
    //   └── Zone B
    $this->warehouse = Location::create(['name' => 'Warehouse', 'code' => 'WH', 'is_active' => true]);
    $this->zoneA = Location::create(['name' => 'Zone A', 'code' => 'ZA', 'parent_id' => $this->warehouse->id, 'is_active' => true]);
    $this->shelfA1 = Location::create(['name' => 'Shelf A1', 'code' => 'SA1', 'parent_id' => $this->zoneA->id, 'is_active' => true]);
    $this->zoneB = Location::create(['name' => 'Zone B', 'code' => 'ZB', 'parent_id' => $this->warehouse->id, 'is_active' => true]);

    $this->product = Product::create(['name' => 'Widget', 'cost_price' => 10.00]);
});

// --- descendantIds() tests ---

it('descendantIds returns empty array for leaf location', function () {
    expect($this->shelfA1->descendantIds())->toBeArray()->toBeEmpty();
});

it('descendantIds returns direct children', function () {
    $ids = $this->zoneA->descendantIds();

    expect($ids)->toContain($this->shelfA1->id)
        ->and($ids)->not->toContain($this->warehouse->id)
        ->and($ids)->not->toContain($this->zoneB->id);
});

it('descendantIds returns all nested descendants', function () {
    $ids = $this->warehouse->descendantIds();

    expect($ids)->toContain($this->zoneA->id)
        ->and($ids)->toContain($this->zoneB->id)
        ->and($ids)->toContain($this->shelfA1->id);
});

it('descendantIds does not include the location itself', function () {
    $ids = $this->warehouse->descendantIds();

    expect($ids)->not->toContain($this->warehouse->id);
});

// --- scopeAtOrBelow() tests ---

it('atOrBelow scope includes the location itself', function () {
    $this->product->addStock(10, $this->warehouse);

    $stocks = Stock::where('stockable_type', Product::class)
        ->where('stockable_id', $this->product->id)
        ->atOrBelow($this->warehouse)
        ->get();

    expect($stocks)->toHaveCount(1)
        ->and($stocks->first()->location_id)->toBe($this->warehouse->id);
});

it('atOrBelow scope includes stock from child locations', function () {
    $this->product->addStock(10, $this->warehouse);
    $this->product->addStock(20, $this->zoneA);
    $this->product->addStock(30, $this->shelfA1);
    $this->product->addStock(15, $this->zoneB);

    $stocks = Stock::where('stockable_type', Product::class)
        ->where('stockable_id', $this->product->id)
        ->atOrBelow($this->warehouse)
        ->get();

    expect($stocks)->toHaveCount(4);
});

it('atOrBelow scope excludes unrelated locations', function () {
    $unrelated = Location::create(['name' => 'Other', 'code' => 'OTH', 'is_active' => true]);
    $this->product->addStock(10, $this->zoneA);
    $this->product->addStock(15, $this->shelfA1);
    $this->product->addStock(5, $unrelated);

    $stocks = Stock::where('stockable_type', Product::class)
        ->where('stockable_id', $this->product->id)
        ->atOrBelow($this->zoneA)
        ->get();

    expect($stocks)->toHaveCount(2) // zoneA + shelfA1
        ->and($stocks->pluck('location_id')->toArray())->not->toContain($unrelated->id);
});

// --- totalStock() with includeChildren ---

it('totalStock with includeChildren sums stock across hierarchy', function () {
    $this->product->addStock(10, $this->warehouse);
    $this->product->addStock(20, $this->zoneA);
    $this->product->addStock(30, $this->shelfA1);
    $this->product->addStock(15, $this->zoneB);

    $total = $this->product->totalStock($this->warehouse, includeChildren: true);

    expect($total)->toEqual(75.0);
});

it('totalStock without includeChildren only counts the given location', function () {
    $this->product->addStock(10, $this->warehouse);
    $this->product->addStock(20, $this->zoneA);

    $total = $this->product->totalStock($this->warehouse, includeChildren: false);

    expect($total)->toEqual(10.0);
});

it('totalStock with no location still sums all locations', function () {
    $this->product->addStock(10, $this->warehouse);
    $this->product->addStock(20, $this->zoneA);

    expect($this->product->totalStock())->toEqual(30.0);
});

// --- availableStock() with includeChildren ---

it('availableStock with includeChildren sums available across hierarchy', function () {
    $this->product->addStock(50, $this->zoneA);
    $this->product->addStock(30, $this->shelfA1);
    $this->product->reserve(10, $this->zoneA);

    $available = $this->product->availableStock($this->warehouse, includeChildren: true);

    // zoneA: 50 - 10 = 40; shelfA1: 30 - 0 = 30; total = 70
    expect($available)->toEqual(70.0);
});

it('availableStock without includeChildren only counts the given location', function () {
    $this->product->addStock(50, $this->zoneA);
    $this->product->addStock(30, $this->shelfA1);
    $this->product->reserve(10, $this->zoneA);

    $available = $this->product->availableStock($this->zoneA, includeChildren: false);

    expect($available)->toEqual(40.0);
});

// --- reservedStock() with includeChildren ---

it('reservedStock with includeChildren sums reserved across hierarchy', function () {
    $this->product->addStock(50, $this->zoneA);
    $this->product->addStock(30, $this->shelfA1);
    $this->product->reserve(10, $this->zoneA);
    $this->product->reserve(5, $this->shelfA1);

    $reserved = $this->product->reservedStock($this->warehouse, includeChildren: true);

    expect($reserved)->toEqual(15.0);
});

// --- stockSummary() with includeChildren ---

it('stockSummary with includeChildren includes all descendant location entries', function () {
    $this->product->addStock(10, $this->warehouse);
    $this->product->addStock(20, $this->zoneA);
    $this->product->addStock(30, $this->shelfA1);

    $summary = $this->product->stockSummary($this->warehouse, includeChildren: true);

    expect($summary['total_quantity'])->toEqual(60.0)
        ->and($summary['locations'])->toHaveCount(3);
});

it('stockSummary without location works as before', function () {
    $this->product->addStock(10, $this->warehouse);
    $this->product->addStock(20, $this->zoneA);

    $summary = $this->product->stockSummary();

    expect($summary['total_quantity'])->toEqual(30.0)
        ->and($summary['locations'])->toHaveCount(2);
});
