<?php

use Aldeebhasan\Inventorix\DTOs\StockOperationDto;
use Aldeebhasan\Inventorix\Enums\SerialStatus;
use Aldeebhasan\Inventorix\Exceptions\InvalidSerialOperationException;
use Aldeebhasan\Inventorix\Facades\Inventorix;
use Aldeebhasan\Inventorix\Models\Location;
use Aldeebhasan\Inventorix\Models\Serial;
use Aldeebhasan\Inventorix\Tests\Support\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->location = Location::create(['name' => 'Warehouse A', 'code' => 'WH-A', 'is_active' => true]);
    $this->product = Product::create(['name' => 'Widget', 'cost_price' => 10.00]);
});

// ---------------------------------------------------------------------------
// Feature disabled (default)
// ---------------------------------------------------------------------------

it('creates no serials when serial_tracking is disabled', function () {
    config()->set('inventorix.serial_tracking.enabled', false);

    Inventorix::addStock($this->product, 5, $this->location);

    expect(Serial::count())->toBe(0);
});

it('does not consume serials on deduct when serial_tracking is disabled', function () {
    config()->set('inventorix.serial_tracking.enabled', false);

    Inventorix::addStock($this->product, 5, $this->location);
    Inventorix::deductStock($this->product, 3, $this->location);

    expect(Serial::count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Auto-generation on addStock
// ---------------------------------------------------------------------------

it('auto-generates one serial per unit when serial_tracking is enabled', function () {
    config()->set('inventorix.serial_tracking.enabled', true);

    Inventorix::addStock($this->product, 4, $this->location);

    expect(Serial::count())->toBe(4);
    expect(Serial::where('status', SerialStatus::Available->value)->count())->toBe(4);
});

it('auto-generated serials are linked to the inbound movement and location', function () {
    config()->set('inventorix.serial_tracking.enabled', true);

    Inventorix::addStock($this->product, 2, $this->location);

    $serials = Serial::all();

    foreach ($serials as $serial) {
        expect($serial->location_id)->toBe($this->location->id)
            ->and($serial->stockable_type)->toBe(Product::class)
            ->and($serial->stockable_id)->toBe($this->product->id)
            ->and($serial->movement_id)->not->toBeNull();
    }
});

it('auto-generated serial numbers are unique', function () {
    config()->set('inventorix.serial_tracking.enabled', true);

    Inventorix::addStock($this->product, 10, $this->location);

    $distinct = Serial::distinct()->count('serial_number');
    expect($distinct)->toBe(10);
});

// ---------------------------------------------------------------------------
// Auto-consumption on deductStock
// ---------------------------------------------------------------------------

it('auto-consumes oldest available serials on deductStock', function () {
    config()->set('inventorix.serial_tracking.enabled', true);

    Inventorix::addStock($this->product, 5, $this->location);

    $beforeSerials = Serial::orderBy('created_at')->orderBy('id')->pluck('serial_number')->all();

    Inventorix::deductStock($this->product, 3, $this->location);

    expect(Serial::where('status', SerialStatus::Sold->value)->count())->toBe(3)
        ->and(Serial::where('status', SerialStatus::Available->value)->count())->toBe(2);

    // The 3 oldest were consumed
    $soldSerials = Serial::where('status', SerialStatus::Sold->value)
        ->orderBy('created_at')->orderBy('id')
        ->pluck('serial_number')->all();

    expect($soldSerials)->toBe(array_slice($beforeSerials, 0, 3));
});

it('throws InvalidSerialOperationException when not enough serials available on deduct', function () {
    config()->set('inventorix.serial_tracking.enabled', true);

    // Add 5 units but manually consume serials so serial count < stock quantity
    Inventorix::addStock($this->product, 5, $this->location);

    // Force-mark all serials as Sold directly so stock quantity is still 5
    // but no Available serials remain — simulates serial/stock drift
    Serial::query()->update(['status' => SerialStatus::Sold->value]);

    Inventorix::deductStock($this->product, 3, $this->location);
})->throws(InvalidSerialOperationException::class);

// ---------------------------------------------------------------------------
// Explicit serial override
// ---------------------------------------------------------------------------

it('uses explicit serials from DTO on addStock instead of auto-generating', function () {
    config()->set('inventorix.serial_tracking.enabled', true);

    Inventorix::addStock($this->product, 3, $this->location, new StockOperationDto(
        serials: ['CUSTOM-001', 'CUSTOM-002', 'CUSTOM-003']
    ));

    $numbers = Serial::orderBy('serial_number')->pluck('serial_number')->all();

    expect($numbers)->toBe(['CUSTOM-001', 'CUSTOM-002', 'CUSTOM-003']);
});

it('uses explicit serials from DTO on deductStock instead of auto-selecting', function () {
    config()->set('inventorix.serial_tracking.enabled', true);

    Inventorix::addStock($this->product, 3, $this->location, new StockOperationDto(
        serials: ['SN001', 'SN002', 'SN003']
    ));

    Inventorix::deductStock($this->product, 1, $this->location, new StockOperationDto(
        serials: ['SN003']  // explicitly pick the last one, not the oldest
    ));

    expect(Serial::where('serial_number', 'SN003')->first()->status)->toBe(SerialStatus::Sold)
        ->and(Serial::where('serial_number', 'SN001')->first()->status)->toBe(SerialStatus::Available)
        ->and(Serial::where('serial_number', 'SN002')->first()->status)->toBe(SerialStatus::Available);
});

it('throws when explicit serial count does not match quantity', function () {
    config()->set('inventorix.serial_tracking.enabled', true);

    Inventorix::addStock($this->product, 3, $this->location, new StockOperationDto(
        serials: ['SN001', 'SN002']  // 2 serials for quantity 3
    ));
})->throws(InvalidSerialOperationException::class);

it('throws when explicit serial to deduct is not available', function () {
    config()->set('inventorix.serial_tracking.enabled', true);

    Inventorix::addStock($this->product, 1, $this->location, new StockOperationDto(
        serials: ['SN-REAL']
    ));

    Inventorix::deductStock($this->product, 1, $this->location, new StockOperationDto(
        serials: ['SN-GHOST']
    ));
})->throws(InvalidSerialOperationException::class);

// ---------------------------------------------------------------------------
// HasInventory relations
// ---------------------------------------------------------------------------

it('serials() relation returns all serials for the stockable', function () {
    config()->set('inventorix.serial_tracking.enabled', true);

    Inventorix::addStock($this->product, 3, $this->location);

    expect($this->product->serials()->count())->toBe(3);
});

it('availableSerials() returns only available serials', function () {
    config()->set('inventorix.serial_tracking.enabled', true);

    Inventorix::addStock($this->product, 4, $this->location);
    Inventorix::deductStock($this->product, 2, $this->location);

    expect($this->product->availableSerials()->count())->toBe(2);
});

it('availableSerials() scopes to a specific location', function () {
    config()->set('inventorix.serial_tracking.enabled', true);

    $locationB = Location::create(['name' => 'Warehouse B', 'code' => 'WH-B', 'is_active' => true]);

    Inventorix::addStock($this->product, 3, $this->location);
    Inventorix::addStock($this->product, 2, $locationB);

    expect($this->product->availableSerials($this->location)->count())->toBe(3)
        ->and($this->product->availableSerials($locationB)->count())->toBe(2);
});

// ---------------------------------------------------------------------------
// Reservation serial tracking
// ---------------------------------------------------------------------------

it('reserve() marks serials as Reserved and links reservation_id', function () {
    config()->set('inventorix.serial_tracking.enabled', true);

    Inventorix::addStock($this->product, 5, $this->location);
    $reservation = Inventorix::reserve($this->product, 3, $this->location);

    expect(Serial::where('status', SerialStatus::Reserved->value)->count())->toBe(3)
        ->and(Serial::where('status', SerialStatus::Available->value)->count())->toBe(2)
        ->and(Serial::where('reservation_id', $reservation->id)->count())->toBe(3);
});

it('reserve() auto-selects oldest available serials (FIFO)', function () {
    config()->set('inventorix.serial_tracking.enabled', true);

    Inventorix::addStock($this->product, 5, $this->location, new StockOperationDto(
        serials: ['SN001', 'SN002', 'SN003', 'SN004', 'SN005']
    ));

    Inventorix::reserve($this->product, 2, $this->location);

    $reserved = Serial::where('status', SerialStatus::Reserved->value)
        ->orderBy('serial_number')
        ->pluck('serial_number')
        ->all();

    expect($reserved)->toBe(['SN001', 'SN002']);
});

it('reserve() with explicit serials in DTO locks those specific serials', function () {
    config()->set('inventorix.serial_tracking.enabled', true);

    Inventorix::addStock($this->product, 3, $this->location, new StockOperationDto(
        serials: ['SN-A', 'SN-B', 'SN-C']
    ));

    Inventorix::reserve($this->product, 2, $this->location, new StockOperationDto(
        serials: ['SN-C', 'SN-A']
    ));

    expect(Serial::where('serial_number', 'SN-A')->first()->status)->toBe(SerialStatus::Reserved)
        ->and(Serial::where('serial_number', 'SN-C')->first()->status)->toBe(SerialStatus::Reserved)
        ->and(Serial::where('serial_number', 'SN-B')->first()->status)->toBe(SerialStatus::Available);
});

it('release() returns Reserved serials back to Available', function () {
    config()->set('inventorix.serial_tracking.enabled', true);

    Inventorix::addStock($this->product, 3, $this->location);
    $reservation = Inventorix::reserve($this->product, 2, $this->location);

    expect(Serial::where('status', SerialStatus::Reserved->value)->count())->toBe(2);

    Inventorix::releaseReservation($reservation);

    expect(Serial::where('status', SerialStatus::Reserved->value)->count())->toBe(0)
        ->and(Serial::where('status', SerialStatus::Available->value)->count())->toBe(3)
        ->and(Serial::whereNotNull('reservation_id')->count())->toBe(0);
});

it('fulfill() transitions Reserved serials to Sold', function () {
    config()->set('inventorix.serial_tracking.enabled', true);

    Inventorix::addStock($this->product, 4, $this->location);
    $reservation = Inventorix::reserve($this->product, 3, $this->location);

    $reservedNumbers = Serial::where('reservation_id', $reservation->id)
        ->pluck('serial_number')
        ->sort()
        ->values()
        ->all();

    Inventorix::fulfillReservation($reservation);

    $soldNumbers = Serial::where('status', SerialStatus::Sold->value)
        ->pluck('serial_number')
        ->sort()
        ->values()
        ->all();

    expect($soldNumbers)->toBe($reservedNumbers)
        ->and(Serial::where('status', SerialStatus::Available->value)->count())->toBe(1)
        ->and(Serial::where('status', SerialStatus::Reserved->value)->count())->toBe(0);
});

it('fulfill() clears reservation_id after consumption', function () {
    config()->set('inventorix.serial_tracking.enabled', true);

    Inventorix::addStock($this->product, 2, $this->location);
    $reservation = Inventorix::reserve($this->product, 2, $this->location);

    Inventorix::fulfillReservation($reservation);

    expect(Serial::whereNotNull('reservation_id')->count())->toBe(0)
        ->and(Serial::where('status', SerialStatus::Sold->value)->count())->toBe(2);
});

it('reservedSerials() returns only reserved serials for a stockable', function () {
    config()->set('inventorix.serial_tracking.enabled', true);

    Inventorix::addStock($this->product, 5, $this->location);
    Inventorix::reserve($this->product, 2, $this->location);

    expect($this->product->reservedSerials()->count())->toBe(2)
        ->and($this->product->availableSerials()->count())->toBe(3);
});

it('reserve() does nothing to serials when serial_tracking is disabled', function () {
    config()->set('inventorix.serial_tracking.enabled', false);

    Inventorix::addStock($this->product, 3, $this->location);
    Inventorix::reserve($this->product, 2, $this->location);

    expect(Serial::count())->toBe(0);
});
