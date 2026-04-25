<?php

use Aldeebhasan\Inventorix\DTOs\StockOperationDto;
use Aldeebhasan\Inventorix\Enums\CostingStrategy;
use Aldeebhasan\Inventorix\Enums\MovementType;
use Aldeebhasan\Inventorix\Facades\Inventorix;
use Aldeebhasan\Inventorix\Models\Location;
use Aldeebhasan\Inventorix\Models\Movement;
use Aldeebhasan\Inventorix\Models\Stock;
use Aldeebhasan\Inventorix\Support\HookRegistry;
use Aldeebhasan\Inventorix\Tests\Support\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->location = Location::create(['name' => 'WH-A', 'code' => 'WH-A', 'is_active' => true]);
    $this->product = Product::create(['name' => 'Widget', 'cost_price' => 10.00]);

    // Flush hooks between tests so registration does not bleed across
    app(HookRegistry::class)->flush();
});

// ---------------------------------------------------------------------------
// 4.1 — Operation Hooks
// ---------------------------------------------------------------------------

describe('4.1 — beforeAdd / afterAdd hooks', function () {

    it('beforeAdd fires before stock is incremented', function () {
        $qtyBefore = null;

        Inventorix::beforeAdd(function ($stockable, $location, $qty) use (&$qtyBefore) {
            $qtyBefore = (float) (Stock::where('stockable_type', get_class($stockable))
                ->where('stockable_id', $stockable->getKey())
                ->where('location_id', $location->id)
                ->value('quantity') ?? 0.0);
        });

        Inventorix::addStock($this->product, 50, $this->location);

        expect($qtyBefore)->toEqual(0.0)
            ->and($this->product->totalStock($this->location))->toEqual(50.0);
    });

    it('afterAdd fires after the movement is recorded', function () {
        $captured = null;

        Inventorix::afterAdd(function (Stock $stock, Movement $movement) use (&$captured) {
            $captured = $movement;
        });

        Inventorix::addStock($this->product, 30, $this->location);

        expect($captured)->not->toBeNull()
            ->and((float) $captured->quantity)->toEqual(30.0);
    });

    it('beforeAdd can abort the operation by throwing — transaction is rolled back', function () {
        Inventorix::beforeAdd(fn () => throw new RuntimeException('Blocked'));

        expect(fn () => Inventorix::addStock($this->product, 10, $this->location))
            ->toThrow(RuntimeException::class, 'Blocked');

        expect($this->product->totalStock($this->location))->toEqual(0.0);
    });

    it('multiple beforeAdd hooks fire in registration order', function () {
        $order = [];
        Inventorix::beforeAdd(function () use (&$order) {
            $order[] = 1;
        });
        Inventorix::beforeAdd(function () use (&$order) {
            $order[] = 2;
        });

        Inventorix::addStock($this->product, 5, $this->location);

        expect($order)->toEqual([1, 2]);
    });
});

describe('4.1 — beforeDeduct / afterDeduct hooks', function () {

    it('beforeDeduct fires before stock is decremented', function () {
        Inventorix::addStock($this->product, 100, $this->location);

        $qtyBefore = null;
        Inventorix::beforeDeduct(function ($stockable, $location, $qty) use (&$qtyBefore) {
            $qtyBefore = (float) (Stock::where('stockable_type', get_class($stockable))
                ->where('stockable_id', $stockable->getKey())
                ->where('location_id', $location->id)
                ->value('quantity') ?? 0.0);
        });

        Inventorix::deductStock($this->product, 30, $this->location);

        expect($qtyBefore)->toEqual(100.0);
    });

    it('afterDeduct fires after the movement is recorded', function () {
        Inventorix::addStock($this->product, 50, $this->location);

        $captured = null;
        Inventorix::afterDeduct(function (Stock $stock, Movement $movement) use (&$captured) {
            $captured = $movement;
        });

        Inventorix::deductStock($this->product, 20, $this->location);

        expect($captured)->not->toBeNull()
            ->and((float) $captured->quantity)->toEqual(20.0);
    });

    it('beforeDeduct can abort the operation — stock is unchanged', function () {
        Inventorix::addStock($this->product, 50, $this->location);

        Inventorix::beforeDeduct(fn () => throw new RuntimeException('Deduct blocked'));

        expect(fn () => Inventorix::deductStock($this->product, 10, $this->location))
            ->toThrow(RuntimeException::class, 'Deduct blocked');

        expect($this->product->totalStock($this->location))->toEqual(50.0);
    });
});

// ---------------------------------------------------------------------------
// 4.3 — Swappable Costing Strategy per Stockable
// ---------------------------------------------------------------------------

describe('4.3 — per-stockable costing strategy', function () {

    it('uses the global config strategy when no inventorixCostingStrategy method is defined', function () {
        config()->set('inventorix.costing_strategy', 'fifo');

        Inventorix::addStock($this->product, 20, $this->location, new StockOperationDto(cost: 10.0));
        Inventorix::addStock($this->product, 20, $this->location, new StockOperationDto(cost: 20.0));
        Inventorix::deductStock($this->product, 20, $this->location);

        $deduction = Movement::where('type', MovementType::Deduct->value)->first();

        // FIFO: oldest lot first → cost 10.0
        expect((float) $deduction->cost_per_unit)->toEqual(10.0);
    });

    it('uses the stockable-level strategy overriding the global config', function () {
        config()->set('inventorix.costing_strategy', 'fifo');

        // Anonymous subclass that declares LIFO
        $lifoProduct = new class extends Product
        {
            protected $table = 'products';

            public function inventorixCostingStrategy(): CostingStrategy
            {
                return CostingStrategy::Lifo;
            }
        };
        $lifoProduct = $lifoProduct::create(['name' => 'LIFO Widget', 'cost_price' => 0]);

        Inventorix::addStock($lifoProduct, 20, $this->location, new StockOperationDto(cost: 10.0));
        Inventorix::addStock($lifoProduct, 20, $this->location, new StockOperationDto(cost: 20.0));
        Inventorix::deductStock($lifoProduct, 20, $this->location);

        $deduction = Movement::where('stockable_type', get_class($lifoProduct))
            ->where('stockable_id', $lifoProduct->getKey())
            ->where('type', MovementType::Deduct->value)
            ->first();

        // LIFO: newest lot first → cost 20.0 despite global FIFO config
        expect((float) $deduction->cost_per_unit)->toEqual(20.0);
    });

    it('different stockables can use different strategies simultaneously', function () {
        config()->set('inventorix.costing_strategy', 'fifo');

        $lifoProduct = new class extends Product
        {
            protected $table = 'products';

            public function inventorixCostingStrategy(): CostingStrategy
            {
                return CostingStrategy::Lifo;
            }
        };
        $lifoProduct = $lifoProduct::create(['name' => 'LIFO', 'cost_price' => 0]);

        // FIFO product (uses global config)
        Inventorix::addStock($this->product, 20, $this->location, new StockOperationDto(cost: 5.0));
        Inventorix::addStock($this->product, 20, $this->location, new StockOperationDto(cost: 15.0));
        Inventorix::deductStock($this->product, 20, $this->location);

        // LIFO product (uses method override)
        Inventorix::addStock($lifoProduct, 20, $this->location, new StockOperationDto(cost: 5.0));
        Inventorix::addStock($lifoProduct, 20, $this->location, new StockOperationDto(cost: 15.0));
        Inventorix::deductStock($lifoProduct, 20, $this->location);

        $fifoDeduct = Movement::where('stockable_type', get_class($this->product))
            ->where('type', MovementType::Deduct->value)->first();
        $lifoDeduct = Movement::where('stockable_type', get_class($lifoProduct))
            ->where('type', MovementType::Deduct->value)->first();

        expect((float) $fifoDeduct->cost_per_unit)->toEqual(5.0)   // FIFO: oldest
            ->and((float) $lifoDeduct->cost_per_unit)->toEqual(15.0); // LIFO: newest
    });
});
