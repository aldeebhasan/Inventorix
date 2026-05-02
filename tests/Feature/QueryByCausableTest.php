<?php

use Aldeebhasan\Inventorix\DTOs\StockOperationDto;
use Aldeebhasan\Inventorix\Enums\MovementType;
use Aldeebhasan\Inventorix\Facades\Inventorix;
use Aldeebhasan\Inventorix\Models\Location;
use Aldeebhasan\Inventorix\Tests\Support\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->location = Location::create(['name' => 'Warehouse A', 'code' => 'WH-A', 'is_active' => true]);
    $this->product = Product::create(['name' => 'Widget', 'cost_price' => 10.00]);
    $this->causable = Product::create(['name' => 'PO-001', 'cost_price' => 0]);
});

// ---------------------------------------------------------------------------
// movementsByCausable
// ---------------------------------------------------------------------------

it('returns movements produced by a specific causable', function () {
    $dto = new StockOperationDto(causable: $this->causable, cost: 10.0);
    Inventorix::addStock($this->product, 50, $this->location, $dto);

    $movements = Inventorix::movementsByCausable($this->causable)->get();

    expect($movements)->toHaveCount(1)
        ->and((float) $movements->first()->quantity)->toEqual(50.0);
});

it('excludes movements from other causables', function () {
    $otherCausable = Product::create(['name' => 'PO-002', 'cost_price' => 0]);

    Inventorix::addStock($this->product, 50, $this->location, new StockOperationDto(causable: $this->causable));
    Inventorix::addStock($this->product, 20, $this->location, new StockOperationDto(causable: $otherCausable));

    expect(Inventorix::movementsByCausable($this->causable)->count())->toBe(1)
        ->and(Inventorix::movementsByCausable($otherCausable)->count())->toBe(1);
});

it('returns empty builder when causable has no movements', function () {
    $orphan = Product::create(['name' => 'PO-999', 'cost_price' => 0]);

    expect(Inventorix::movementsByCausable($orphan)->count())->toBe(0);
});

it('filters movementsByCausable by location', function () {
    $locationB = Location::create(['name' => 'Warehouse B', 'code' => 'WH-B', 'is_active' => true]);
    $dto = new StockOperationDto(causable: $this->causable);

    Inventorix::addStock($this->product, 30, $this->location, $dto);
    Inventorix::addStock($this->product, 20, $locationB, $dto);

    $filtered = Inventorix::movementsByCausable($this->causable, ['location' => $this->location])->count();

    expect($filtered)->toBe(1);
});

it('filters movementsByCausable by movement type', function () {
    Inventorix::addStock($this->product, 50, $this->location, new StockOperationDto(causable: $this->causable));
    Inventorix::deductStock($this->product, 10, $this->location, new StockOperationDto(causable: $this->causable));

    $adds = Inventorix::movementsByCausable($this->causable, ['type' => MovementType::Add->value])->count();
    $deducts = Inventorix::movementsByCausable($this->causable, ['type' => MovementType::Deduct->value])->count();

    expect($adds)->toBe(1)->and($deducts)->toBe(1);
});

it('groups multi-movement bulk transactions under the causable', function () {
    $locationB = Location::create(['name' => 'Warehouse B', 'code' => 'WH-B', 'is_active' => true]);

    Inventorix::bulk(function ($tx) use ($locationB) {
        $dto = new StockOperationDto(transaction: $tx, causable: $this->causable);
        Inventorix::addStock($this->product, 30, $this->location, $dto);
        Inventorix::addStock($this->product, 20, $locationB, $dto);
    }, new StockOperationDto(causable: $this->causable));

    expect(Inventorix::movementsByCausable($this->causable)->count())->toBe(2);
});

// ---------------------------------------------------------------------------
// valuationByCausable
// ---------------------------------------------------------------------------

it('computes valuation for stock received from a causable', function () {
    Inventorix::addStock($this->product, 10, $this->location, new StockOperationDto(causable: $this->causable, cost: 20.0));

    expect(Inventorix::valuationByCausable($this->causable))->toEqual(200.0);
});

it('sums across multiple add movements from the same causable', function () {
    $dto = new StockOperationDto(causable: $this->causable, cost: 15.0);
    Inventorix::addStock($this->product, 10, $this->location, $dto);
    Inventorix::addStock($this->product, 5, $this->location, $dto);

    // 10×15 + 5×15 = 225
    expect(Inventorix::valuationByCausable($this->causable))->toEqual(225.0);
});

it('excludes deduct movements from valuation', function () {
    Inventorix::addStock($this->product, 20, $this->location, new StockOperationDto(causable: $this->causable, cost: 10.0));
    Inventorix::deductStock($this->product, 5, $this->location, new StockOperationDto(causable: $this->causable));

    // Only the Add contributes: 20×10 = 200
    expect(Inventorix::valuationByCausable($this->causable))->toEqual(200.0);
});

it('returns 0.0 when causable has no costed movements', function () {
    Inventorix::addStock($this->product, 10, $this->location, new StockOperationDto(causable: $this->causable, cost: 0));

    expect(Inventorix::valuationByCausable($this->causable))->toEqual(0.0);
});

it('returns 0.0 when causable has no movements at all', function () {
    $orphan = Product::create(['name' => 'PO-999', 'cost_price' => 0]);

    expect(Inventorix::valuationByCausable($orphan))->toEqual(0.0);
});
