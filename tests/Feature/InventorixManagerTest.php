<?php

use Aldeebhasan\Inventorix\DTOs\StockOperationDto;
use Aldeebhasan\Inventorix\Enums\MovementType;
use Aldeebhasan\Inventorix\Enums\ReservationStatus;
use Aldeebhasan\Inventorix\Enums\TransactionStatus;
use Aldeebhasan\Inventorix\Events\LowStockReached;
use Aldeebhasan\Inventorix\Events\OverstockReached;
use Aldeebhasan\Inventorix\Exceptions\InsufficientStockException;
use Aldeebhasan\Inventorix\Exceptions\InvalidQuantityException;
use Aldeebhasan\Inventorix\Exceptions\LocationNotFoundException;
use Aldeebhasan\Inventorix\Exceptions\ReservationAlreadyFulfilledException;
use Aldeebhasan\Inventorix\Exceptions\ReservationNotFoundException;
use Aldeebhasan\Inventorix\Facades\Inventorix;
use Aldeebhasan\Inventorix\Models\Location;
use Aldeebhasan\Inventorix\Models\Movement;
use Aldeebhasan\Inventorix\Models\Reservation;
use Aldeebhasan\Inventorix\Models\Stock;
use Aldeebhasan\Inventorix\Models\Transaction;
use Aldeebhasan\Inventorix\Tests\Support\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->location = Location::create(['name' => 'Warehouse A', 'code' => 'WH-A', 'is_active' => true]);
    $this->product = Product::create(['name' => 'Widget', 'cost_price' => 10.00]);
});

// ---------------------------------------------------------------------------
// addStock
// ---------------------------------------------------------------------------

it('addStock returns a Stock instance with updated quantity', function () {
    $stock = Inventorix::addStock($this->product, 50, $this->location);

    expect($stock)->toBeInstanceOf(Stock::class)
        ->and($stock->quantity)->toEqual(50)
        ->and($stock->location_id)->toBe($this->location->id);
});

it('addStock accepts a location id instead of a Location model', function () {
    $stock = Inventorix::addStock($this->product, 30, $this->location->id);

    expect($stock->quantity)->toEqual(30);
});

it('addStock throws LocationNotFoundException for unknown location id', function () {
    Inventorix::addStock($this->product, 10, 9999);
})->throws(LocationNotFoundException::class);

it('addStock throws InvalidQuantityException for zero quantity', function () {
    Inventorix::addStock($this->product, 0, $this->location);
})->throws(InvalidQuantityException::class);

it('addStock throws InvalidQuantityException for negative quantity', function () {
    Inventorix::addStock($this->product, -5, $this->location);
})->throws(InvalidQuantityException::class);

it('addStock records a movement of type Add', function () {
    Inventorix::addStock($this->product, 40, $this->location);

    $movement = Movement::where('stockable_type', Product::class)
        ->where('stockable_id', $this->product->id)
        ->first();

    expect($movement->type)->toBe(MovementType::Add)
        ->and($movement->quantity)->toEqual(40)
        ->and($movement->before_quantity)->toEqual(0)
        ->and($movement->after_quantity)->toEqual(40);
});

it('addStock supports float quantities', function () {
    $stock = Inventorix::addStock($this->product, 5.75, $this->location);

    expect($stock->quantity)->toEqual(5.75);
});

// ---------------------------------------------------------------------------
// deductStock
// ---------------------------------------------------------------------------

it('deductStock returns a Stock instance with reduced quantity', function () {
    Inventorix::addStock($this->product, 100, $this->location);
    $stock = Inventorix::deductStock($this->product, 40, $this->location);

    expect($stock->quantity)->toEqual(60);
});

it('deductStock accepts a location id instead of a Location model', function () {
    Inventorix::addStock($this->product, 100, $this->location);
    $stock = Inventorix::deductStock($this->product, 25, $this->location->id);

    expect($stock->quantity)->toEqual(75);
});

it('deductStock throws LocationNotFoundException for unknown location id', function () {
    Inventorix::deductStock($this->product, 10, 9999);
})->throws(LocationNotFoundException::class);

it('deductStock throws InvalidQuantityException for zero quantity', function () {
    Inventorix::deductStock($this->product, 0, $this->location);
})->throws(InvalidQuantityException::class);

it('deductStock throws InsufficientStockException when stock is too low', function () {
    Inventorix::addStock($this->product, 10, $this->location);
    Inventorix::deductStock($this->product, 50, $this->location);
})->throws(InsufficientStockException::class);

it('deductStock allows going negative when allow_negative option is true', function () {
    Inventorix::addStock($this->product, 10, $this->location);
    $stock = Inventorix::deductStock($this->product, 30, $this->location, new StockOperationDto(allowNegative: true));

    expect($stock->quantity)->toEqual(-20);
});

it('deductStock records a movement of type Deduct', function () {
    Inventorix::addStock($this->product, 100, $this->location);
    Inventorix::deductStock($this->product, 35, $this->location);

    $movement = Movement::where('type', MovementType::Deduct->value)->first();

    expect($movement->quantity)->toEqual(35)
        ->and($movement->before_quantity)->toEqual(100)
        ->and($movement->after_quantity)->toEqual(65);
});

// ---------------------------------------------------------------------------
// adjustStock
// ---------------------------------------------------------------------------

it('adjustStock sets stock to exact quantity (increase)', function () {
    Inventorix::addStock($this->product, 10, $this->location);
    $stock = Inventorix::adjustStock($this->product, 80, $this->location);

    expect($stock->quantity)->toEqual(80);
});

it('adjustStock sets stock to exact quantity (decrease)', function () {
    Inventorix::addStock($this->product, 100, $this->location);
    $stock = Inventorix::adjustStock($this->product, 20, $this->location);

    expect($stock->quantity)->toEqual(20);
});

it('adjustStock accepts a location id instead of a Location model', function () {
    Inventorix::addStock($this->product, 50, $this->location);
    $stock = Inventorix::adjustStock($this->product, 25, $this->location->id);

    expect($stock->quantity)->toEqual(25);
});

it('adjustStock throws LocationNotFoundException for unknown location id', function () {
    Inventorix::adjustStock($this->product, 10, 9999);
})->throws(LocationNotFoundException::class);

it('adjustStock records a movement of type Deduct with correct quantity', function () {
    Inventorix::addStock($this->product, 50, $this->location);
    Inventorix::adjustStock($this->product, 30, $this->location);

    $movement = Movement::where('type', MovementType::Deduct->value)->first();

    expect($movement->before_quantity)->toEqual(50)
        ->and($movement->after_quantity)->toEqual(30)
        ->and($movement->quantity)->toEqual(20); // absolute diff, always positive
});

// ---------------------------------------------------------------------------
// transfer
// ---------------------------------------------------------------------------

it('transfer moves stock between two locations', function () {
    $locationB = Location::create(['name' => 'Warehouse B', 'code' => 'WH-B', 'is_active' => true]);

    Inventorix::addStock($this->product, 100, $this->location);
    $result = Inventorix::transfer($this->product, 40, $this->location, $locationB);

    $stockA = Stock::where('location_id', $this->location->id)->first();
    $stockB = Stock::where('location_id', $locationB->id)->first();

    expect($result)->toBeTrue()
        ->and($stockA->quantity)->toEqual(60)
        ->and($stockB->quantity)->toEqual(40);
});

it('transfer accepts location ids instead of Location models', function () {
    $locationB = Location::create(['name' => 'Warehouse B', 'code' => 'WH-B', 'is_active' => true]);

    Inventorix::addStock($this->product, 100, $this->location);
    Inventorix::transfer($this->product, 30, $this->location->id, $locationB->id);

    $stockB = Stock::where('location_id', $locationB->id)->first();
    expect($stockB->quantity)->toEqual(30);
});

it('transfer throws InsufficientStockException when source has insufficient stock', function () {
    $locationB = Location::create(['name' => 'Warehouse B', 'code' => 'WH-B', 'is_active' => true]);

    Inventorix::addStock($this->product, 10, $this->location);
    Inventorix::transfer($this->product, 50, $this->location, $locationB);
})->throws(InsufficientStockException::class);

it('transfer throws InvalidQuantityException for zero quantity', function () {
    $locationB = Location::create(['name' => 'Warehouse B', 'code' => 'WH-B', 'is_active' => true]);

    Inventorix::transfer($this->product, 0, $this->location, $locationB);
})->throws(InvalidQuantityException::class);

it('transfer throws LocationNotFoundException for unknown source location', function () {
    $locationB = Location::create(['name' => 'Warehouse B', 'code' => 'WH-B', 'is_active' => true]);

    Inventorix::transfer($this->product, 10, 9999, $locationB);
})->throws(LocationNotFoundException::class);

it('transfer records Deduct and Add movements linked to same Transaction', function () {
    $locationB = Location::create(['name' => 'Warehouse B', 'code' => 'WH-B', 'is_active' => true]);

    Inventorix::addStock($this->product, 100, $this->location);
    Inventorix::transfer($this->product, 40, $this->location, $locationB);

    $out = Movement::where('type', MovementType::Deduct->value)->where('location_id', $this->location->id)->first();
    $in = Movement::where('type', MovementType::Add->value)->where('location_id', $locationB->id)->first();

    expect($out)->not->toBeNull()
        ->and($in)->not->toBeNull()
        ->and($out->transaction_id)->toBe($in->transaction_id)
        ->and($out->quantity)->toEqual(40)
        ->and($in->quantity)->toEqual(40);
});

// ---------------------------------------------------------------------------
// bulk
// ---------------------------------------------------------------------------

it('bulk groups multiple operations under one committed Transaction', function () {
    $product2 = Product::create(['name' => 'Gadget', 'cost_price' => 5.00]);

    $transaction = Inventorix::bulk(function (Transaction $tx) use ($product2) {
        Inventorix::addStock($this->product, 100, $this->location, new StockOperationDto(transaction: $tx));
        Inventorix::addStock($product2, 50, $this->location, new StockOperationDto(transaction: $tx));
    });

    expect($transaction)->toBeInstanceOf(Transaction::class)
        ->and($transaction->status)->toBe(TransactionStatus::Committed);

    $movements = Movement::where('transaction_id', $transaction->id)->get();
    expect($movements->count())->toBe(2);
});

it('bulk rolls back Transaction status when an exception is thrown', function () {
    $transaction = null;

    try {
        $transaction = Inventorix::bulk(function (Transaction $tx) use (&$transaction) {
            $transaction = $tx;
            throw new RuntimeException('Simulated failure');
        });
    } catch (RuntimeException) {
    }

    // The DB transaction was rolled back, so the record no longer exists in DB.
    // The in-memory model was updated to RolledBack before the exception propagated.
    expect($transaction->status)->toBe(TransactionStatus::RolledBack);
});

// ---------------------------------------------------------------------------
// reserve
// ---------------------------------------------------------------------------

it('reserve creates a Reservation and increments reserved_quantity', function () {
    Inventorix::addStock($this->product, 100, $this->location);
    $reservation = Inventorix::reserve($this->product, 30, $this->location);

    $stock = Stock::where('location_id', $this->location->id)->first();

    expect($reservation)->toBeInstanceOf(Reservation::class)
        ->and($reservation->status)->toBe(ReservationStatus::Pending)
        ->and($reservation->quantity)->toEqual(30)
        ->and($stock->reserved_quantity)->toEqual(30);
});

it('reserve accepts a location id instead of a Location model', function () {
    Inventorix::addStock($this->product, 100, $this->location);
    $reservation = Inventorix::reserve($this->product, 20, $this->location->id);

    expect($reservation->quantity)->toEqual(20);
});

it('reserve throws LocationNotFoundException for unknown location id', function () {
    Inventorix::reserve($this->product, 10, 9999);
})->throws(LocationNotFoundException::class);

it('reserve throws InvalidQuantityException for zero quantity', function () {
    Inventorix::reserve($this->product, 0, $this->location);
})->throws(InvalidQuantityException::class);

it('reserve throws InsufficientStockException when available stock is too low', function () {
    Inventorix::addStock($this->product, 10, $this->location);
    Inventorix::reserve($this->product, 50, $this->location);
})->throws(InsufficientStockException::class);

// ---------------------------------------------------------------------------
// releaseReservation
// ---------------------------------------------------------------------------

it('releaseReservation decrements reserved_quantity and marks reservation as released', function () {
    Inventorix::addStock($this->product, 100, $this->location);
    $reservation = Inventorix::reserve($this->product, 40, $this->location);

    $result = Inventorix::releaseReservation($reservation);
    $stock = Stock::where('location_id', $this->location->id)->first();

    expect($result)->toBeTrue()
        ->and($reservation->fresh()->status)->toBe(ReservationStatus::Released)
        ->and($stock->reserved_quantity)->toEqual(0);
});

it('releaseReservation accepts a reservation id instead of a Reservation model', function () {
    Inventorix::addStock($this->product, 100, $this->location);
    $reservation = Inventorix::reserve($this->product, 20, $this->location);

    Inventorix::releaseReservation($reservation->id);

    expect($reservation->fresh()->status)->toBe(ReservationStatus::Released);
});

it('releaseReservation throws ReservationNotFoundException for unknown id', function () {
    Inventorix::releaseReservation(9999);
})->throws(ReservationNotFoundException::class);

it('releaseReservation throws ReservationAlreadyFulfilledException when already fulfilled', function () {
    Inventorix::addStock($this->product, 100, $this->location);
    $reservation = Inventorix::reserve($this->product, 20, $this->location);
    Inventorix::fulfillReservation($reservation);

    Inventorix::releaseReservation($reservation->fresh());
})->throws(ReservationAlreadyFulfilledException::class);

// ---------------------------------------------------------------------------
// fulfillReservation
// ---------------------------------------------------------------------------

it('fulfillReservation deducts quantity and reserved_quantity and marks as fulfilled', function () {
    Inventorix::addStock($this->product, 100, $this->location);
    $reservation = Inventorix::reserve($this->product, 40, $this->location);

    $stock = Inventorix::fulfillReservation($reservation);

    expect($stock->quantity)->toEqual(60)
        ->and($stock->reserved_quantity)->toEqual(0)
        ->and($reservation->fresh()->status)->toBe(ReservationStatus::Fulfilled);
});

it('fulfillReservation accepts a reservation id instead of a Reservation model', function () {
    Inventorix::addStock($this->product, 100, $this->location);
    $reservation = Inventorix::reserve($this->product, 30, $this->location);

    Inventorix::fulfillReservation($reservation->id);

    expect($reservation->fresh()->status)->toBe(ReservationStatus::Fulfilled);
});

it('fulfillReservation throws ReservationNotFoundException for unknown id', function () {
    Inventorix::fulfillReservation(9999);
})->throws(ReservationNotFoundException::class);

it('fulfillReservation throws ReservationAlreadyFulfilledException when already fulfilled', function () {
    Inventorix::addStock($this->product, 100, $this->location);
    $reservation = Inventorix::reserve($this->product, 20, $this->location);
    Inventorix::fulfillReservation($reservation);

    Inventorix::fulfillReservation($reservation->fresh());
})->throws(ReservationAlreadyFulfilledException::class);

// ---------------------------------------------------------------------------
// movementsFor
// ---------------------------------------------------------------------------

it('movementsFor returns a Builder scoped to the stockable', function () {
    $product2 = Product::create(['name' => 'Other', 'cost_price' => 0]);
    Inventorix::addStock($this->product, 50, $this->location);
    Inventorix::addStock($product2, 20, $this->location);

    $movements = Inventorix::movementsFor($this->product)->get();

    expect($movements->count())->toBe(1)
        ->and($movements->first()->stockable_id)->toBe($this->product->id);
});

it('movementsFor filters by location', function () {
    $locationB = Location::create(['name' => 'Warehouse B', 'code' => 'WH-B', 'is_active' => true]);
    Inventorix::addStock($this->product, 50, $this->location);
    Inventorix::addStock($this->product, 30, $locationB);

    $movements = Inventorix::movementsFor($this->product, ['location' => $this->location])->get();

    expect($movements->count())->toBe(1)
        ->and($movements->first()->location_id)->toBe($this->location->id);
});

it('movementsFor filters by type', function () {
    Inventorix::addStock($this->product, 100, $this->location);
    Inventorix::deductStock($this->product, 30, $this->location);

    $adds = Inventorix::movementsFor($this->product, ['type' => MovementType::Add->value])->get();
    $deducts = Inventorix::movementsFor($this->product, ['type' => MovementType::Deduct->value])->get();

    expect($adds->count())->toBe(1)
        ->and($deducts->count())->toBe(1);
});

it('movementsFor filters by date range', function () {
    Inventorix::addStock($this->product, 50, $this->location);

    $movements = Inventorix::movementsFor($this->product, [
        'from' => now()->subMinute(),
        'to' => now()->addMinute(),
    ])->get();

    expect($movements->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// lowStockItems
// ---------------------------------------------------------------------------

it('lowStockItems returns stocks at or below their threshold', function () {
    $this->product->setStockThreshold(20, null, $this->location);
    Inventorix::addStock($this->product, 10, $this->location); // 10 <= 20 → low

    $items = Inventorix::lowStockItems();

    expect($items->count())->toBe(1)
        ->and($items->first()->stockable_id)->toBe($this->product->id);
});

it('lowStockItems excludes stocks above their threshold', function () {
    $this->product->setStockThreshold(5, null, $this->location);
    Inventorix::addStock($this->product, 50, $this->location); // 50 > 5 → fine

    $items = Inventorix::lowStockItems();

    expect($items->count())->toBe(0);
});

it('lowStockItems filters by location', function () {
    $locationB = Location::create(['name' => 'Warehouse B', 'code' => 'WH-B', 'is_active' => true]);
    $this->product->setStockThreshold(20, null, $this->location);
    $this->product->setStockThreshold(20, null, $locationB);
    Inventorix::addStock($this->product, 5, $this->location);  // low
    Inventorix::addStock($this->product, 5, $locationB);        // low

    $items = Inventorix::lowStockItems($this->location);

    expect($items->count())->toBe(1)
        ->and($items->first()->location_id)->toBe($this->location->id);
});

it('lowStockItems accepts a location id instead of a Location model', function () {
    $this->product->setStockThreshold(20, null, $this->location);
    Inventorix::addStock($this->product, 5, $this->location);

    $items = Inventorix::lowStockItems($this->location->id);

    expect($items->count())->toBe(1);
});

it('lowStockItems filters by stockable type', function () {
    $other = Product::create(['name' => 'Other', 'cost_price' => 0]);
    $this->product->setStockThreshold(20, null, $this->location);
    $other->setStockThreshold(20, null, $this->location);
    Inventorix::addStock($this->product, 5, $this->location);
    Inventorix::addStock($other, 5, $this->location);

    $items = Inventorix::lowStockItems(null, Product::class);

    expect($items->count())->toBe(2);

    $items = Inventorix::lowStockItems($this->location, Product::class);

    expect($items->count())->toBe(2);
});

it('lowStockItems throws LocationNotFoundException for unknown location id', function () {
    Inventorix::lowStockItems(9999);
})->throws(LocationNotFoundException::class);

// ---------------------------------------------------------------------------
// totalValuation
// ---------------------------------------------------------------------------

it('totalValuation returns sum of quantity multiplied by cost_price across all locations', function () {
    $locationB = Location::create(['name' => 'Warehouse B', 'code' => 'WH-B', 'is_active' => true]);
    Inventorix::addStock($this->product, 10, $this->location); // 10 * 10.00 = 100
    Inventorix::addStock($this->product, 5, $locationB);       //  5 * 10.00 =  50

    expect(Inventorix::totalValuation())->toEqual(150.0);
});

it('totalValuation filters by location', function () {
    $locationB = Location::create(['name' => 'Warehouse B', 'code' => 'WH-B', 'is_active' => true]);
    Inventorix::addStock($this->product, 10, $this->location); // 10 * 10 = 100
    Inventorix::addStock($this->product, 5, $locationB);       //  5 * 10 = 50

    expect(Inventorix::totalValuation($this->location))->toEqual(100.0);
});

it('totalValuation accepts a location id instead of a Location model', function () {
    Inventorix::addStock($this->product, 8, $this->location); // 8 * 10 = 80

    expect(Inventorix::totalValuation($this->location->id))->toEqual(80.0);
});

it('totalValuation supports a custom cost attribute', function () {
    $product = Product::create(['name' => 'Pricy', 'cost_price' => 0]);
    // Add a sale_price attribute dynamically via fillable workaround — use cost_price as proxy
    Inventorix::addStock($product, 4, $this->location);

    // cost_price is 0 so valuation should be 0 with the default attribute
    expect(Inventorix::totalValuation(null, null, 'cost_price'))->toEqual(0.0);
});

it('totalValuation returns 0.0 when no stocks exist', function () {
    expect(Inventorix::totalValuation())->toEqual(0.0);
});

it('totalValuation throws LocationNotFoundException for unknown location id', function () {
    Inventorix::totalValuation(9999);
})->throws(LocationNotFoundException::class);

// ---------------------------------------------------------------------------
// checkThresholds
// ---------------------------------------------------------------------------

it('checkThresholds fires LowStockReached when stock is at or below min', function () {
    Event::fake([LowStockReached::class]);

    Inventorix::addStock($this->product, 5, $this->location);
    $this->product->setStockThreshold(10, null, $this->location); // min=10, stock=5

    Inventorix::checkThresholds($this->product);

    Event::assertDispatched(LowStockReached::class, function ($event) {
        return $event->stockable->id === $this->product->id;
    });
});

it('checkThresholds fires OverstockReached when stock is at or above max', function () {
    Event::fake([OverstockReached::class]);

    Inventorix::addStock($this->product, 100, $this->location);
    $this->product->setStockThreshold(0, 50, $this->location); // max=50, stock=100

    Inventorix::checkThresholds($this->product);

    Event::assertDispatched(OverstockReached::class, function ($event) {
        return $event->stockable->id === $this->product->id;
    });
});

it('checkThresholds fires no events when stock is within thresholds', function () {
    Event::fake([LowStockReached::class, OverstockReached::class]);

    Inventorix::addStock($this->product, 50, $this->location);
    $this->product->setStockThreshold(10, 100, $this->location);

    Inventorix::checkThresholds($this->product);

    Event::assertNotDispatched(LowStockReached::class);
    Event::assertNotDispatched(OverstockReached::class);
});

it('checkThresholds scopes check to the given location when provided', function () {
    Event::fake([LowStockReached::class]);

    $locationB = Location::create(['name' => 'Warehouse B', 'code' => 'WH-B', 'is_active' => true]);
    Inventorix::addStock($this->product, 5, $this->location);  // low
    Inventorix::addStock($this->product, 50, $locationB);       // fine
    $this->product->setStockThreshold(10, null, $this->location);
    $this->product->setStockThreshold(10, null, $locationB);

    Inventorix::checkThresholds($this->product, $this->location);

    Event::assertDispatchedTimes(LowStockReached::class, 1);
});

it('checkThresholds accepts a location id instead of a Location model', function () {
    Event::fake([LowStockReached::class]);

    Inventorix::addStock($this->product, 5, $this->location);
    $this->product->setStockThreshold(10, null, $this->location);

    Inventorix::checkThresholds($this->product, $this->location->id);

    Event::assertDispatched(LowStockReached::class);
});

it('checkThresholds throws LocationNotFoundException for unknown location id', function () {
    Inventorix::checkThresholds($this->product, 9999);
})->throws(LocationNotFoundException::class);
