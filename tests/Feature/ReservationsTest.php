<?php

use Aldeebhasan\Inventorix\Enums\ReservationStatus;
use Aldeebhasan\Inventorix\Exceptions\InsufficientStockException;
use Aldeebhasan\Inventorix\Exceptions\ReservationAlreadyFulfilledException;
use Aldeebhasan\Inventorix\Models\Location;
use Aldeebhasan\Inventorix\Models\Reservation;
use Aldeebhasan\Inventorix\Tests\Support\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->location = Location::create(['name' => 'Warehouse A', 'code' => 'WH-A', 'is_active' => true]);
    $this->product = Product::create(['name' => 'Widget', 'cost_price' => 10.00]);
    $this->product->addStock(100, $this->location);
});

it('reserve increases reserved_quantity, not quantity', function () {
    $this->product->reserve(30, $this->location);

    $stock = $this->product->stockAt($this->location);

    expect($stock->quantity)->toBe(100)
        ->and($stock->reserved_quantity)->toBe(30);
});

it('reserve creates a Reservation record with status pending', function () {
    $reservation = $this->product->reserve(30, $this->location);

    expect($reservation)->toBeInstanceOf(Reservation::class)
        ->and($reservation->status)->toBe(ReservationStatus::Pending)
        ->and($reservation->quantity)->toBe(30);
});

it('reserve throws InsufficientStockException when not enough available', function () {
    $this->product->reserve(80, $this->location);
    // Only 20 available now
    $this->product->reserve(30, $this->location);
})->throws(InsufficientStockException::class);

it('releaseReservation decrements reserved_quantity and sets status released', function () {
    $reservation = $this->product->reserve(40, $this->location);
    $this->product->releaseReservation($reservation);

    $stock = $this->product->stockAt($this->location);
    $reservation->refresh();

    expect($stock->reserved_quantity)->toBe(0)
        ->and($reservation->status)->toBe(ReservationStatus::Released);
});

it('fulfillReservation decrements both quantity and reserved_quantity, sets status fulfilled', function () {
    $reservation = $this->product->reserve(40, $this->location);
    $this->product->fulfillReservation($reservation);

    $stock = $this->product->stockAt($this->location);
    $reservation->refresh();

    expect($stock->quantity)->toBe(60)
        ->and($stock->reserved_quantity)->toBe(0)
        ->and($reservation->status)->toBe(ReservationStatus::Fulfilled);
});

it('cannot release already-fulfilled reservation', function () {
    $reservation = $this->product->reserve(40, $this->location);
    $this->product->fulfillReservation($reservation);

    $reservation->refresh();
    $this->product->releaseReservation($reservation);
})->throws(ReservationAlreadyFulfilledException::class);

it('cannot fulfill already-fulfilled reservation', function () {
    $reservation = $this->product->reserve(40, $this->location);
    $this->product->fulfillReservation($reservation);

    $reservation->refresh();
    $this->product->fulfillReservation($reservation);
})->throws(ReservationAlreadyFulfilledException::class);
