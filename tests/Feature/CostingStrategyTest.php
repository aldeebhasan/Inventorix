<?php

use Aldeebhasan\Inventorix\DTOs\StockOperationDto;
use Aldeebhasan\Inventorix\Enums\MovementType;
use Aldeebhasan\Inventorix\Facades\Inventorix;
use Aldeebhasan\Inventorix\Models\Location;
use Aldeebhasan\Inventorix\Models\Movement;
use Aldeebhasan\Inventorix\Services\ValuationService;
use Aldeebhasan\Inventorix\Tests\Support\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// ValuationService::evaluate — unit tests (in-memory collection)
// ---------------------------------------------------------------------------

describe('ValuationService::evaluate', function () {
    beforeEach(fn () => $this->valuation = new ValuationService);

    it('returns 0.0 when movements collection is empty', function () {
        expect($this->valuation->evaluate(collect()))->toEqual(0.0);
    });

    it('returns 0.0 when all movements have null cost_per_unit', function () {
        $m = new Movement(['type' => MovementType::Add, 'quantity' => 5, 'cost_per_unit' => null]);

        expect($this->valuation->evaluate(collect([$m])))->toEqual(0.0);
    });

    it('values a fully intact lot correctly', function () {
        $m = new Movement(['type' => MovementType::Add, 'quantity' => 10, 'cost_per_unit' => 5.0, 'consumed_quantity' => 0]);

        // 10 remaining × $5 = $50
        expect($this->valuation->evaluate(collect([$m])))->toEqual(50.0);
    });

    it('uses remaining quantity (quantity - consumed_quantity) per lot', function () {
        $m = new Movement(['type' => MovementType::Add, 'quantity' => 10, 'cost_per_unit' => 5.0, 'consumed_quantity' => 6]);

        // 4 remaining × $5 = $20
        expect($this->valuation->evaluate(collect([$m])))->toEqual(20.0);
    });

    it('skips fully consumed lots', function () {
        $consumed = new Movement(['type' => MovementType::Add, 'quantity' => 10, 'cost_per_unit' => 5.0, 'consumed_quantity' => 10]);
        $intact = new Movement(['type' => MovementType::Add, 'quantity' => 8, 'cost_per_unit' => 8.0, 'consumed_quantity' => 0]);

        // Only intact lot contributes: 8 × $8 = $64
        expect($this->valuation->evaluate(collect([$consumed, $intact])))->toEqual(64.0);
    });

    it('sums multiple lots correctly', function () {
        $m1 = new Movement(['type' => MovementType::Add, 'quantity' => 10, 'cost_per_unit' => 5.0, 'consumed_quantity' => 4]);
        $m2 = new Movement(['type' => MovementType::Add, 'quantity' => 8, 'cost_per_unit' => 8.0, 'consumed_quantity' => 0]);

        // (10-4)×5 + (8-0)×8 = 30 + 64 = $94
        expect($this->valuation->evaluate(collect([$m1, $m2])))->toEqual(94.0);
    });

    it('includes TransferIn movements', function () {
        $m = new Movement(['type' => MovementType::TransferIn, 'quantity' => 5, 'cost_per_unit' => 7.0, 'consumed_quantity' => 0]);

        expect($this->valuation->evaluate(collect([$m])))->toEqual(35.0);
    });

    it('includes positive Adjustment movements', function () {
        $m = new Movement(['type' => MovementType::Adjustment, 'quantity' => 5, 'cost_per_unit' => 9.0, 'consumed_quantity' => 0]);

        expect($this->valuation->evaluate(collect([$m])))->toEqual(45.0);
    });

    it('excludes negative Adjustment movements', function () {
        $add = new Movement(['type' => MovementType::Add, 'quantity' => 10, 'cost_per_unit' => 5.0, 'consumed_quantity' => 0]);
        $adj = new Movement(['type' => MovementType::Adjustment, 'quantity' => -5, 'cost_per_unit' => 5.0, 'consumed_quantity' => 0]);

        expect($this->valuation->evaluate(collect([$add, $adj])))->toEqual(50.0);
    });

    it('excludes Deduct movements', function () {
        $add = new Movement(['type' => MovementType::Add, 'quantity' => 10, 'cost_per_unit' => 5.0, 'consumed_quantity' => 5]);
        $deduct = new Movement(['type' => MovementType::Deduct, 'quantity' => 5, 'cost_per_unit' => 5.0, 'consumed_quantity' => 0]);

        expect($this->valuation->evaluate(collect([$add, $deduct])))->toEqual(25.0);
    });

    it('skips movements with null cost_per_unit', function () {
        $costed = new Movement(['type' => MovementType::Add, 'quantity' => 10, 'cost_per_unit' => 8.0, 'consumed_quantity' => 0]);
        $uncosted = new Movement(['type' => MovementType::Add, 'quantity' => 5, 'cost_per_unit' => null, 'consumed_quantity' => 0]);

        expect($this->valuation->evaluate(collect([$costed, $uncosted])))->toEqual(80.0);
    });
});

// ---------------------------------------------------------------------------
// cost_per_unit capture on movements
// ---------------------------------------------------------------------------

describe('cost_per_unit capture on movements', function () {
    beforeEach(function () {
        $this->location = Location::create(['name' => 'Warehouse A', 'code' => 'WH-A', 'is_active' => true]);
        $this->product = Product::create(['name' => 'Widget', 'cost_price' => 10.00]);
    });

    it('addStock records cost_per_unit from the stockable cost_price by default', function () {
        Inventorix::addStock($this->product, 5, $this->location);

        $movement = Movement::first();
        expect((float) $movement->cost_per_unit)->toEqual(10.0);
    });

    it('addStock records cost_per_unit from the cost option when provided', function () {
        Inventorix::addStock($this->product, 5, $this->location, new StockOperationDto(cost: 12.50));

        $movement = Movement::first();
        expect((float) $movement->cost_per_unit)->toEqual(12.5);
    });

    it('addStock records null cost_per_unit when stockable has no cost_price', function () {
        $product = Product::create(['name' => 'NoCost']);

        Inventorix::addStock($product, 3, $this->location);

        $movement = Movement::first();
        expect($movement->cost_per_unit)->toBeNull();
    });

    it('transfer records cost_per_unit on the TransferIn movement', function () {
        $locationB = Location::create(['name' => 'Warehouse B', 'code' => 'WH-B', 'is_active' => true]);
        Inventorix::addStock($this->product, 10, $this->location);
        Inventorix::transfer($this->product, 5, $this->location, $locationB);

        $transferIn = Movement::where('type', MovementType::TransferIn->value)->first();
        expect((float) $transferIn->cost_per_unit)->toEqual(10.0);
    });

    it('adjustStock records cost_per_unit only on positive adjustments', function () {
        Inventorix::addStock($this->product, 10, $this->location);
        Inventorix::adjustStock($this->product, 15, $this->location);
        Inventorix::adjustStock($this->product, 10, $this->location);

        $positive = Movement::where('type', MovementType::Adjustment->value)->where('quantity', '>', 0)->first();
        $negative = Movement::where('type', MovementType::Adjustment->value)->where('quantity', '<', 0)->first();

        expect((float) $positive->cost_per_unit)->toEqual(10.0)
            ->and($negative->cost_per_unit)->toBeNull();
    });
});

// ---------------------------------------------------------------------------
// Integration — totalValuation
// ---------------------------------------------------------------------------

describe('totalValuation', function () {
    beforeEach(function () {
        $this->location = Location::create(['name' => 'Warehouse A', 'code' => 'WH-A', 'is_active' => true]);
    });

    it('values remaining stock correctly after a deduction', function () {
        $product = Product::create(['name' => 'Widget', 'cost_price' => 0]);

        Inventorix::addStock($product, 10, $this->location, new StockOperationDto(cost: 5.0));
        Inventorix::addStock($product, 10, $this->location, new StockOperationDto(cost: 8.0));
        Inventorix::deductStock($product, 10, $this->location);

        // FIFO default: oldest consumed → newest (10 @ $8) remains = $80
        expect(Inventorix::totalValuation())->toEqual(80.0);
    });

    it('respects the configured allocation strategy at write time', function () {
        config()->set('inventorix.costing_strategy', 'lifo');

        $product = Product::create(['name' => 'Widget', 'cost_price' => 0]);

        Inventorix::addStock($product, 10, $this->location, new StockOperationDto(cost: 5.0));

        Carbon::setTestNow(Carbon::now()->addSecond());
        Inventorix::addStock($product, 10, $this->location, new StockOperationDto(cost: 8.0));
        Carbon::setTestNow(null);

        Inventorix::deductStock($product, 10, $this->location);

        // LIFO: newest consumed first → oldest (10 @ $5) remains = $50
        expect(Inventorix::totalValuation())->toEqual(50.0);
    });

    it('returns 0.0 when no stocks exist', function () {
        expect(Inventorix::totalValuation())->toEqual(0.0);
    });

    it('filters correctly by location', function () {
        $locationB = Location::create(['name' => 'Warehouse B', 'code' => 'WH-B', 'is_active' => true]);
        $product = Product::create(['name' => 'Widget', 'cost_price' => 0]);

        Inventorix::addStock($product, 10, $this->location, new StockOperationDto(cost: 5.0));
        Inventorix::addStock($product, 5, $locationB, new StockOperationDto(cost: 8.0));

        expect(Inventorix::totalValuation($this->location))->toEqual(50.0);
        expect(Inventorix::totalValuation($locationB))->toEqual(40.0);
        expect(Inventorix::totalValuation())->toEqual(90.0);
    });

    it('filters correctly by stockable', function () {
        $productA = Product::create(['name' => 'Widget A', 'cost_price' => 0]);
        $productB = Product::create(['name' => 'Widget B', 'cost_price' => 0]);

        Inventorix::addStock($productA, 10, $this->location, new StockOperationDto(cost: 5.0));
        Inventorix::addStock($productB, 8, $this->location, new StockOperationDto(cost: 10.0));

        // Product A: 10 × $5 = $50
        expect(Inventorix::totalValuation(null, $productA))->toEqual(50.0);
        // Product B: 8 × $10 = $80
        expect(Inventorix::totalValuation(null, $productB))->toEqual(80.0);
        // All: $130
        expect(Inventorix::totalValuation())->toEqual(130.0);
    });

    it('filters by both location and stockable', function () {
        $locationB = Location::create(['name' => 'Warehouse B', 'code' => 'WH-B', 'is_active' => true]);
        $product = Product::create(['name' => 'Widget', 'cost_price' => 0]);

        Inventorix::addStock($product, 10, $this->location, new StockOperationDto(cost: 5.0));
        Inventorix::addStock($product, 6, $locationB, new StockOperationDto(cost: 8.0));

        // Product at location A only: 10 × $5 = $50
        expect(Inventorix::totalValuation($this->location, $product))->toEqual(50.0);
        // Product at location B only: 6 × $8 = $48
        expect(Inventorix::totalValuation($locationB, $product))->toEqual(48.0);
    });

    it('supports a custom cost attribute fallback', function () {
        expect(Inventorix::totalValuation(null, null, 'cost_price'))->toEqual(0.0);
    });
});
