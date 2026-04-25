<?php

use Aldeebhasan\Inventorix\Facades\Inventorix;
use Aldeebhasan\Inventorix\Models\Location;
use Aldeebhasan\Inventorix\Tests\Support\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->location = Location::create(['name' => 'Warehouse A', 'code' => 'WH-A', 'is_active' => true]);
    $this->product = Product::create(['name' => 'Widget', 'cost_price' => 10.00]);
});

// ---------------------------------------------------------------------------
// velocity()
// ---------------------------------------------------------------------------

it('returns 0.0 velocity when there are no deductions in the window', function () {
    Inventorix::addStock($this->product, 100, $this->location);

    expect(Inventorix::stockVelocity($this->product, $this->location, 30))->toEqual(0.0);
});

it('calculates average daily consumption correctly', function () {
    Inventorix::addStock($this->product, 100, $this->location);
    // 60 units deducted in a 30-day window → 2.0 per day
    Inventorix::deductStock($this->product, 60, $this->location);

    expect(Inventorix::stockVelocity($this->product, $this->location, 30))->toEqual(2.0);
});

it('only counts deductions within the given window', function () {
    Inventorix::addStock($this->product, 200, $this->location);

    // Deduction 40 days ago — outside the 30-day window
    Carbon::setTestNow(now()->subDays(40));
    Inventorix::deductStock($this->product, 90, $this->location);
    Carbon::setTestNow(null);

    // Deduction today — inside the 30-day window (30 units / 30 days = 1.0)
    Inventorix::deductStock($this->product, 30, $this->location);

    expect(Inventorix::stockVelocity($this->product, $this->location, 30))->toEqual(1.0);
});

it('sums multiple deductions within the window', function () {
    Inventorix::addStock($this->product, 200, $this->location);
    Inventorix::deductStock($this->product, 10, $this->location);
    Inventorix::deductStock($this->product, 20, $this->location);

    // 30 total / 30 days = 1.0
    expect(Inventorix::stockVelocity($this->product, $this->location, 30))->toEqual(1.0);
});

it('scopes velocity to the given location', function () {
    $locationB = Location::create(['name' => 'Warehouse B', 'code' => 'WH-B', 'is_active' => true]);
    Inventorix::addStock($this->product, 200, $this->location);
    Inventorix::addStock($this->product, 200, $locationB);

    Inventorix::deductStock($this->product, 60, $this->location);
    Inventorix::deductStock($this->product, 90, $locationB);

    expect(Inventorix::stockVelocity($this->product, $this->location, 30))->toEqual(2.0)
        ->and(Inventorix::stockVelocity($this->product, $locationB, 30))->toEqual(3.0);
});

it('is also accessible on the stockable model via the HasInventory trait', function () {
    Inventorix::addStock($this->product, 100, $this->location);
    Inventorix::deductStock($this->product, 30, $this->location);

    expect($this->product->stockVelocity($this->location, 30))->toEqual(1.0);
});

// ---------------------------------------------------------------------------
// daysOfStock()
// ---------------------------------------------------------------------------

it('returns INF when velocity is zero', function () {
    Inventorix::addStock($this->product, 50, $this->location);

    expect(Inventorix::daysOfStock($this->product, $this->location))->toEqual(INF);
});

it('calculates days of stock correctly', function () {
    Inventorix::addStock($this->product, 100, $this->location);
    // 60 deducted in 30 days → velocity = 2/day; available = 40 → 40/2 = 20 days
    Inventorix::deductStock($this->product, 60, $this->location);

    expect(Inventorix::daysOfStock($this->product, $this->location, 30))->toEqual(20.0);
});

it('returns 0.0 days when no stock remains', function () {
    Inventorix::addStock($this->product, 30, $this->location);
    Inventorix::deductStock($this->product, 30, $this->location);

    // velocity = 1/day, available = 0 → 0/1 = 0.0
    expect(Inventorix::daysOfStock($this->product, $this->location, 30))->toEqual(0.0);
});

it('daysOfStock is also accessible on the stockable model via the HasInventory trait', function () {
    Inventorix::addStock($this->product, 60, $this->location);
    Inventorix::deductStock($this->product, 30, $this->location);
    // velocity = 1/day, available = 30 → 30.0 days
    expect($this->product->daysOfStock($this->location, 30))->toEqual(30.0);
});

// ---------------------------------------------------------------------------
// peakDemandDay()
// ---------------------------------------------------------------------------

it('returns null when there are no deductions in the window', function () {
    Inventorix::addStock($this->product, 100, $this->location);

    expect(Inventorix::peakDemandDay($this->product, $this->location, 90))->toBeNull();
});

it('identifies the day with highest deduction volume', function () {
    Inventorix::addStock($this->product, 500, $this->location);

    $dayA = now()->subDays(5)->startOfDay();
    $dayB = now()->subDays(2)->startOfDay();

    Carbon::setTestNow($dayA);
    Inventorix::deductStock($this->product, 10, $this->location);
    Carbon::setTestNow($dayA->copy()->addHours(3));
    Inventorix::deductStock($this->product, 15, $this->location); // dayA total = 25

    Carbon::setTestNow($dayB);
    Inventorix::deductStock($this->product, 40, $this->location); // dayB total = 40 ← peak
    Carbon::setTestNow(null);

    $peak = Inventorix::peakDemandDay($this->product, $this->location, 90);

    expect($peak)->not->toBeNull()
        ->and($peak->toDateString())->toEqual($dayB->toDateString());
});

it('excludes deductions outside the window', function () {
    Inventorix::addStock($this->product, 500, $this->location);

    // Large deduction outside the 30-day window
    Carbon::setTestNow(now()->subDays(35));
    Inventorix::deductStock($this->product, 200, $this->location);
    Carbon::setTestNow(null);

    // Smaller deduction inside the window
    Inventorix::deductStock($this->product, 5, $this->location);

    $peak = Inventorix::peakDemandDay($this->product, $this->location, 30);

    expect($peak)->not->toBeNull()
        ->and($peak->toDateString())->toEqual(now()->toDateString());
});

it('peakDemandDay is also accessible on the stockable model via the HasInventory trait', function () {
    Inventorix::addStock($this->product, 100, $this->location);
    Inventorix::deductStock($this->product, 20, $this->location);

    expect($this->product->peakDemandDay($this->location, 30))->not->toBeNull();
});
