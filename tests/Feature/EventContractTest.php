<?php

use Aldeebhasan\Inventorix\DTOs\StockOperationDto;
use Aldeebhasan\Inventorix\Events\StockAdded;
use Aldeebhasan\Inventorix\Events\StockAdjusted;
use Aldeebhasan\Inventorix\Events\StockDeducted;
use Aldeebhasan\Inventorix\Events\StockTransferred;
use Aldeebhasan\Inventorix\Facades\Inventorix;
use Aldeebhasan\Inventorix\Models\Location;
use Aldeebhasan\Inventorix\Tests\Support\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

// Event::fake() must be called before the first Inventorix facade call in each test.
// The Inventorix class is a singleton; its injected Dispatcher is captured at first
// resolution. Calling Event::fake() first ensures the singleton is built with the
// fake dispatcher so all subsequent dispatches are intercepted.

beforeEach(function () {
    $this->location = Location::create(['name' => 'Warehouse A', 'code' => 'WH-A', 'is_active' => true]);
    $this->product = Product::create(['name' => 'Widget', 'cost_price' => 10.00]);
    $this->causable = Product::create(['name' => 'Causable', 'cost_price' => 0]);
});

it('surfaces causable and externalReference on StockAdded', function () {
    Event::fake([StockAdded::class]);

    $dto = new StockOperationDto(
        causable: $this->causable,
        externalReference: 'PO-123',
    );

    Inventorix::addStock($this->product, 10, $this->location, $dto);

    Event::assertDispatched(StockAdded::class, function (StockAdded $event) {
        return $event->causable?->is($this->causable)
            && $event->externalReference === 'PO-123';
    });
});

it('surfaces causable and externalReference on StockDeducted', function () {
    // Fake before any Inventorix call so the singleton captures the fake dispatcher.
    Event::fake([StockDeducted::class]);

    Inventorix::addStock($this->product, 20, $this->location);

    $dto = new StockOperationDto(
        causable: $this->causable,
        externalReference: 'SO-456',
    );

    Inventorix::deductStock($this->product, 10, $this->location, $dto);

    Event::assertDispatched(StockDeducted::class, function (StockDeducted $event) {
        return $event->causable?->is($this->causable)
            && $event->externalReference === 'SO-456';
    });
});

it('surfaces causable and externalReference on StockAdjusted', function () {
    Event::fake([StockAdjusted::class]);

    Inventorix::addStock($this->product, 20, $this->location);

    $dto = new StockOperationDto(
        causable: $this->causable,
        externalReference: 'ADJ-789',
    );

    Inventorix::adjustStock($this->product, 15, $this->location, $dto);

    Event::assertDispatched(StockAdjusted::class, function (StockAdjusted $event) {
        return $event->causable?->is($this->causable)
            && $event->externalReference === 'ADJ-789';
    });
});

it('surfaces causable and externalReference on StockTransferred', function () {
    $locationB = Location::create(['name' => 'Warehouse B', 'code' => 'WH-B', 'is_active' => true]);

    Event::fake([StockTransferred::class]);

    Inventorix::addStock($this->product, 50, $this->location);

    $dto = new StockOperationDto(
        causable: $this->causable,
        externalReference: 'TRF-321',
    );

    Inventorix::transfer($this->product, 20, $this->location, $locationB, $dto);

    Event::assertDispatched(StockTransferred::class, function (StockTransferred $event) {
        return $event->causable?->is($this->causable)
            && $event->externalReference === 'TRF-321';
    });
});

it('surfaces null causable and null externalReference when not provided', function () {
    Event::fake([StockAdded::class]);

    Inventorix::addStock($this->product, 5, $this->location);

    Event::assertDispatched(StockAdded::class, function (StockAdded $event) {
        return $event->causable === null && $event->externalReference === null;
    });
});
