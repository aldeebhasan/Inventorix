<?php

use Aldeebhasan\Inventorix\Events\LowStockReached;
use Aldeebhasan\Inventorix\Events\OverstockReached;
use Aldeebhasan\Inventorix\Models\Location;
use Aldeebhasan\Inventorix\Models\Threshold;
use Aldeebhasan\Inventorix\Tests\Support\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->location = Location::create(['name' => 'Warehouse A', 'code' => 'WH-A', 'is_active' => true]);
    $this->product = Product::create(['name' => 'Widget', 'cost_price' => 10.00]);
});

it('setStockThreshold persists threshold', function () {
    $this->product->setStockThreshold(10, 100, $this->location);

    $threshold = Threshold::where('stockable_type', Product::class)
        ->where('stockable_id', $this->product->id)
        ->where('location_id', $this->location->id)
        ->first();

    expect($threshold)->not->toBeNull()
        ->and($threshold->min_quantity)->toBe(10)
        ->and($threshold->max_quantity)->toBe(100);
});

it('setStockThreshold persists global threshold (no location)', function () {
    $this->product->setStockThreshold(5, 50);

    $threshold = Threshold::where('stockable_type', Product::class)
        ->where('stockable_id', $this->product->id)
        ->whereNull('location_id')
        ->first();

    expect($threshold)->not->toBeNull()
        ->and($threshold->min_quantity)->toBe(5);
});

it('LowStockReached event fires when stock drops to min', function () {
    Event::fake([LowStockReached::class]);

    $this->product->addStock(20, $this->location);
    $this->product->setStockThreshold(10, null, $this->location);
    $this->product->deductStock(10, $this->location); // quantity = 10 = min

    Event::assertDispatched(LowStockReached::class, function ($event) {
        return $event->stockable->id === $this->product->id
            && $event->threshold === 10;
    });
});

it('LowStockReached event is not fired when stock is above min', function () {
    Event::fake([LowStockReached::class]);

    $this->product->addStock(50, $this->location);
    $this->product->setStockThreshold(10, null, $this->location);
    $this->product->deductStock(5, $this->location); // quantity = 45, above min

    Event::assertNotDispatched(LowStockReached::class);
});

it('OverstockReached event fires when stock rises to max', function () {
    Event::fake([OverstockReached::class]);

    $this->product->setStockThreshold(0, 50, $this->location);
    $this->product->addStock(50, $this->location); // quantity = 50 = max

    Event::assertDispatched(OverstockReached::class, function ($event) {
        return $event->stockable->id === $this->product->id
            && $event->threshold === 50;
    });
});

it('OverstockReached event is not fired when stock is below max', function () {
    Event::fake([OverstockReached::class]);

    $this->product->setStockThreshold(0, 100, $this->location);
    $this->product->addStock(50, $this->location); // below max

    Event::assertNotDispatched(OverstockReached::class);
});
