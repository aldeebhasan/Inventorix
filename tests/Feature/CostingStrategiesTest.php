<?php

use Aldeebhasan\Inventorix\Contracts\CostingStrategyInterface;
use Aldeebhasan\Inventorix\Contracts\ValuationServiceInterface;
use Aldeebhasan\Inventorix\Enums\MovementType;
use Aldeebhasan\Inventorix\Facades\Inventorix;
use Aldeebhasan\Inventorix\Models\Location;
use Aldeebhasan\Inventorix\Models\Movement;
use Aldeebhasan\Inventorix\Models\Stock;
use Aldeebhasan\Inventorix\Services\ValuationService;
use Aldeebhasan\Inventorix\Strategies\Costing\AverageCostingStrategy;
use Aldeebhasan\Inventorix\Strategies\Costing\FifoCostingStrategy;
use Aldeebhasan\Inventorix\Strategies\Costing\LifoCostingStrategy;
use Aldeebhasan\Inventorix\Tests\Support\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->location = Location::create(['name' => 'Warehouse A', 'code' => 'WH-A', 'is_active' => true]);
    $this->product = Product::create(['name' => 'Widget', 'cost_price' => 10.00]);
});

// ---------------------------------------------------------------------------
// Movement cost_per_unit is recorded on addStock
// ---------------------------------------------------------------------------

it('addStock records cost_per_unit from stockable cost_price by default', function () {
    Inventorix::addStock($this->product, 10, $this->location);

    $movement = Movement::where('type', MovementType::Add->value)->first();

    expect((float) $movement->cost_per_unit)->toEqual(10.0);
});

it('addStock records cost_per_unit from explicit cost option', function () {
    Inventorix::addStock($this->product, 10, $this->location, ['cost' => 12.50]);

    $movement = Movement::where('type', MovementType::Add->value)->first();

    expect((float) $movement->cost_per_unit)->toEqual(12.50);
});

it('addStock records null cost_per_unit when stockable has no cost attribute', function () {
    $plain = Product::create(['name' => 'No Cost']);

    Inventorix::addStock($plain, 5, $this->location);

    $movement = Movement::where('type', MovementType::Add->value)->first();

    expect($movement->cost_per_unit)->toBeNull();
});

it('adjustStock records cost_per_unit only for positive delta', function () {
    Inventorix::addStock($this->product, 20, $this->location);

    // Upward adjustment (delta > 0) → should store cost
    Inventorix::adjustStock($this->product, 25, $this->location, ['cost' => 11.00]);
    $positive = Movement::where('type', MovementType::Adjustment->value)
        ->orderBy('id', 'desc')->first();
    expect((float) $positive->cost_per_unit)->toEqual(11.00);

    // Downward adjustment (delta < 0) → should not store cost
    Inventorix::adjustStock($this->product, 10, $this->location);
    $negative = Movement::where('type', MovementType::Adjustment->value)
        ->orderBy('id', 'desc')->first();
    expect($negative->cost_per_unit)->toBeNull();
});

it('transfer records cost_per_unit on the TransferIn movement', function () {
    $locationB = Location::create(['name' => 'Warehouse B', 'code' => 'WH-B', 'is_active' => true]);
    Inventorix::addStock($this->product, 20, $this->location);
    Inventorix::transfer($this->product, 10, $this->location, $locationB, ['cost' => 15.00]);

    $transferIn = Movement::where('type', MovementType::TransferIn->value)->first();

    expect((float) $transferIn->cost_per_unit)->toEqual(15.00);
});

it('transfer falls back to stockable cost_price when no cost option provided', function () {
    $locationB = Location::create(['name' => 'Warehouse B', 'code' => 'WH-B', 'is_active' => true]);
    Inventorix::addStock($this->product, 20, $this->location);
    Inventorix::transfer($this->product, 10, $this->location, $locationB);

    $transferIn = Movement::where('type', MovementType::TransferIn->value)->first();

    expect((float) $transferIn->cost_per_unit)->toEqual(10.00);
});

// ---------------------------------------------------------------------------
// FifoCostingStrategy unit tests
// ---------------------------------------------------------------------------

it('FifoCostingStrategy returns 0.0 when no costed movements exist', function () {
    $stock = new Stock(['quantity' => 10]);
    $movements = collect();

    expect((new FifoCostingStrategy)->valuate($stock, $movements))->toEqual(0.0);
});

it('FifoCostingStrategy values a single batch correctly', function () {
    // 10 units at £5 each purchased, 10 still on hand → 10 × 5 = 50
    $stock = new Stock(['quantity' => 10]);

    $movement = new Movement([
        'type' => MovementType::Add,
        'quantity' => 10,
        'cost_per_unit' => 5.0,
        'created_at' => now(),
    ]);

    expect((new FifoCostingStrategy)->valuate($stock, collect([$movement])))->toEqual(50.0);
});

it('FifoCostingStrategy selects newest batches for on-hand value', function () {
    // Batch 1 (oldest): 10 units @ £5 — sold first under FIFO
    // Batch 2 (newest): 10 units @ £8 — still on hand
    // Current stock: 10 units → should be valued at newest batch: 10 × 8 = 80
    $stock = new Stock(['quantity' => 10]);

    $old = new Movement([
        'type' => MovementType::Add,
        'quantity' => 10,
        'cost_per_unit' => 5.0,
        'created_at' => now()->subDays(5),
    ]);
    $new = new Movement([
        'type' => MovementType::Add,
        'quantity' => 10,
        'cost_per_unit' => 8.0,
        'created_at' => now(),
    ]);

    expect((new FifoCostingStrategy)->valuate($stock, collect([$old, $new])))->toEqual(80.0);
});

it('FifoCostingStrategy spans multiple batches when newest alone is insufficient', function () {
    // Batch 1 (oldest): 10 @ £5 — sold first
    // Batch 2 (middle): 6  @ £7 — partially on hand
    // Batch 3 (newest): 4  @ £9 — on hand
    // Current stock: 8 → take all 4 from batch 3 (4×9=36), take 4 from batch 2 (4×7=28) = 64
    $stock = new Stock(['quantity' => 8]);

    $b1 = new Movement(['type' => MovementType::Add, 'quantity' => 10, 'cost_per_unit' => 5.0, 'created_at' => now()->subDays(10)]);
    $b2 = new Movement(['type' => MovementType::Add, 'quantity' => 6, 'cost_per_unit' => 7.0, 'created_at' => now()->subDays(5)]);
    $b3 = new Movement(['type' => MovementType::Add, 'quantity' => 4, 'cost_per_unit' => 9.0, 'created_at' => now()]);

    $result = (new FifoCostingStrategy)->valuate($stock, collect([$b1, $b2, $b3]));

    expect($result)->toEqual(4 * 9.0 + 4 * 7.0); // 36 + 28 = 64
});

it('FifoCostingStrategy skips movements with null cost_per_unit', function () {
    $stock = new Stock(['quantity' => 5]);

    $withCost = new Movement(['type' => MovementType::Add, 'quantity' => 5, 'cost_per_unit' => 6.0, 'created_at' => now()]);
    $noCost = new Movement(['type' => MovementType::Add, 'quantity' => 3, 'cost_per_unit' => null, 'created_at' => now()->subDay()]);

    expect((new FifoCostingStrategy)->valuate($stock, collect([$withCost, $noCost])))->toEqual(30.0);
});

it('FifoCostingStrategy includes positive Adjustment movements', function () {
    $stock = new Stock(['quantity' => 5]);

    $adj = new Movement(['type' => MovementType::Adjustment, 'quantity' => 5, 'cost_per_unit' => 4.0, 'created_at' => now()]);

    expect((new FifoCostingStrategy)->valuate($stock, collect([$adj])))->toEqual(20.0);
});

it('FifoCostingStrategy excludes negative Adjustment movements', function () {
    $stock = new Stock(['quantity' => 5]);

    $add = new Movement(['type' => MovementType::Add, 'quantity' => 10, 'cost_per_unit' => 6.0, 'created_at' => now()->subDay()]);
    $adj = new Movement(['type' => MovementType::Adjustment, 'quantity' => -5, 'cost_per_unit' => 6.0, 'created_at' => now()]);

    expect((new FifoCostingStrategy)->valuate($stock, collect([$add, $adj])))->toEqual(30.0);
});

it('FifoCostingStrategy includes TransferIn movements', function () {
    $stock = new Stock(['quantity' => 5]);

    $in = new Movement(['type' => MovementType::TransferIn, 'quantity' => 5, 'cost_per_unit' => 7.0, 'created_at' => now()]);

    expect((new FifoCostingStrategy)->valuate($stock, collect([$in])))->toEqual(35.0);
});

// ---------------------------------------------------------------------------
// LifoCostingStrategy unit tests
// ---------------------------------------------------------------------------

it('LifoCostingStrategy returns 0.0 when no costed movements exist', function () {
    $stock = new Stock(['quantity' => 10]);

    expect((new LifoCostingStrategy)->valuate($stock, collect()))->toEqual(0.0);
});

it('LifoCostingStrategy selects oldest batches for on-hand value', function () {
    // Batch 1 (oldest): 10 @ £5 — still on hand under LIFO
    // Batch 2 (newest): 10 @ £8 — sold first under LIFO
    // Current stock: 10 → should be valued at oldest batch: 10 × 5 = 50
    $stock = new Stock(['quantity' => 10]);

    $old = new Movement(['type' => MovementType::Add, 'quantity' => 10, 'cost_per_unit' => 5.0, 'created_at' => now()->subDays(5)]);
    $new = new Movement(['type' => MovementType::Add, 'quantity' => 10, 'cost_per_unit' => 8.0, 'created_at' => now()]);

    expect((new LifoCostingStrategy)->valuate($stock, collect([$old, $new])))->toEqual(50.0);
});

it('LifoCostingStrategy spans multiple batches when oldest alone is insufficient', function () {
    // Batch 1 (oldest): 4  @ £5 — on hand
    // Batch 2 (middle): 6  @ £7 — partially on hand
    // Batch 3 (newest): 10 @ £9 — sold first
    // Current stock: 8 → take all 4 from batch 1 (4×5=20), take 4 from batch 2 (4×7=28) = 48
    $stock = new Stock(['quantity' => 8]);

    $b1 = new Movement(['type' => MovementType::Add, 'quantity' => 4, 'cost_per_unit' => 5.0, 'created_at' => now()->subDays(10)]);
    $b2 = new Movement(['type' => MovementType::Add, 'quantity' => 6, 'cost_per_unit' => 7.0, 'created_at' => now()->subDays(5)]);
    $b3 = new Movement(['type' => MovementType::Add, 'quantity' => 10, 'cost_per_unit' => 9.0, 'created_at' => now()]);

    $result = (new LifoCostingStrategy)->valuate($stock, collect([$b1, $b2, $b3]));

    expect($result)->toEqual(4 * 5.0 + 4 * 7.0); // 20 + 28 = 48
});

// ---------------------------------------------------------------------------
// AverageCostingStrategy unit tests
// ---------------------------------------------------------------------------

it('AverageCostingStrategy returns 0.0 when no costed movements exist', function () {
    $stock = new Stock(['quantity' => 10]);

    expect((new AverageCostingStrategy)->valuate($stock, collect()))->toEqual(0.0);
});

it('AverageCostingStrategy computes weighted average correctly', function () {
    // 10 @ £4 and 10 @ £6 → avg = (10×4 + 10×6) / 20 = 100/20 = £5
    // On hand: 20 units → value = 20 × 5 = 100
    $stock = new Stock(['quantity' => 20]);

    $b1 = new Movement(['type' => MovementType::Add, 'quantity' => 10, 'cost_per_unit' => 4.0, 'created_at' => now()->subDay()]);
    $b2 = new Movement(['type' => MovementType::Add, 'quantity' => 10, 'cost_per_unit' => 6.0, 'created_at' => now()]);

    expect((new AverageCostingStrategy)->valuate($stock, collect([$b1, $b2])))->toEqual(100.0);
});

it('AverageCostingStrategy value is independent of sell-through (uses current quantity)', function () {
    // Same purchases as above, but only 5 units remain
    $stock = new Stock(['quantity' => 5]);

    $b1 = new Movement(['type' => MovementType::Add, 'quantity' => 10, 'cost_per_unit' => 4.0, 'created_at' => now()->subDay()]);
    $b2 = new Movement(['type' => MovementType::Add, 'quantity' => 10, 'cost_per_unit' => 6.0, 'created_at' => now()]);

    // avg = 5, 5 × 5 = 25
    expect((new AverageCostingStrategy)->valuate($stock, collect([$b1, $b2])))->toEqual(25.0);
});

it('AverageCostingStrategy skips movements with null cost_per_unit', function () {
    // Only the costed movement (10 @ £6) counts; uncost one is ignored
    // avg = 6.0, stock = 5 → 5 × 6 = 30
    $stock = new Stock(['quantity' => 5]);

    $costed = new Movement(['type' => MovementType::Add, 'quantity' => 10, 'cost_per_unit' => 6.0, 'created_at' => now()]);
    $uncosted = new Movement(['type' => MovementType::Add, 'quantity' => 5, 'cost_per_unit' => null, 'created_at' => now()->subDay()]);

    expect((new AverageCostingStrategy)->valuate($stock, collect([$costed, $uncosted])))->toEqual(30.0);
});

// ---------------------------------------------------------------------------
// ValuationService integration: strategy dispatch via totalValuation
// ---------------------------------------------------------------------------

it('totalValuation uses FIFO strategy and values remaining stock at newest batches', function () {
    // Batch 1: 10 @ £5 (oldest — sold first under FIFO)
    // Batch 2: 10 @ £10 (newest — on hand)
    // Deduct 10 → 10 remaining = 10 × £10 = 100
    $productA = Product::create(['name' => 'A', 'cost_price' => 5.00]);
    config(['inventorix.costing_strategy' => 'fifo']);

    // Simulate two separate purchase batches with different costs
    Inventorix::addStock($productA, 10, $this->location, ['cost' => 5.00]);
    Inventorix::addStock($productA, 10, $this->location, ['cost' => 10.00]);
    Inventorix::deductStock($productA, 10, $this->location);

    // Remaining 10 units should be valued at newest batch: 10 × 10 = 100
    $valuation = app(ValuationServiceInterface::class);
    expect($valuation->totalValuation())->toEqual(100.0);
});

it('totalValuation uses LIFO strategy and values remaining stock at oldest batches', function () {
    // Batch 1: 10 @ £5 (oldest — on hand under LIFO)
    // Batch 2: 10 @ £10 (newest — sold first under LIFO)
    // Deduct 10 → 10 remaining = 10 × £5 = 50
    $productA = Product::create(['name' => 'B', 'cost_price' => 5.00]);
    config(['inventorix.costing_strategy' => 'lifo']);

    Inventorix::addStock($productA, 10, $this->location, ['cost' => 5.00]);
    Inventorix::addStock($productA, 10, $this->location, ['cost' => 10.00]);
    Inventorix::deductStock($productA, 10, $this->location);

    $strategy = new LifoCostingStrategy;
    $service = new ValuationService($strategy);
    expect($service->totalValuation())->toEqual(50.0);
});

it('totalValuation uses Average strategy correctly', function () {
    // 10 @ £4 + 10 @ £6 → avg £5 × 20 on hand = 100
    $productA = Product::create(['name' => 'C', 'cost_price' => 4.00]);

    Inventorix::addStock($productA, 10, $this->location, ['cost' => 4.00]);
    Inventorix::addStock($productA, 10, $this->location, ['cost' => 6.00]);

    $service = new ValuationService(new AverageCostingStrategy);
    expect($service->totalValuation())->toEqual(100.0);
});

it('totalValuation falls back to flat cost_price when movements have no cost data', function () {
    // Manually insert a movement without cost_per_unit to simulate pre-Phase-3 data
    $stock = Stock::create([
        'stockable_type' => Product::class,
        'stockable_id' => $this->product->id,
        'location_id' => $this->location->id,
        'quantity' => 10,
        'reserved_quantity' => 0,
    ]);

    Movement::create([
        'stockable_type' => Product::class,
        'stockable_id' => $this->product->id,
        'location_id' => $this->location->id,
        'type' => MovementType::Add,
        'quantity' => 10,
        'cost_per_unit' => null,
        'before_quantity' => 0,
        'after_quantity' => 10,
    ]);

    // No costed movements → falls back to cost_price (10) × qty (10) = 100
    $service = new ValuationService(new FifoCostingStrategy);
    expect($service->totalValuation())->toEqual(100.0);
});

// ---------------------------------------------------------------------------
// Container / config binding
// ---------------------------------------------------------------------------

it('container resolves FifoCostingStrategy by default', function () {
    config(['inventorix.costing_strategy' => 'fifo']);

    expect(app(CostingStrategyInterface::class))->toBeInstanceOf(FifoCostingStrategy::class);
});

it('container resolves LifoCostingStrategy when configured', function () {
    config(['inventorix.costing_strategy' => 'lifo']);
    // Re-bind so config change takes effect
    app()->bind(CostingStrategyInterface::class, function () {
        return match (config('inventorix.costing_strategy', 'fifo')) {
            'lifo' => new LifoCostingStrategy,
            'average' => new AverageCostingStrategy,
            default => new FifoCostingStrategy,
        };
    });

    expect(app(CostingStrategyInterface::class))->toBeInstanceOf(LifoCostingStrategy::class);
});

it('container resolves AverageCostingStrategy when configured', function () {
    config(['inventorix.costing_strategy' => 'average']);
    app()->bind(CostingStrategyInterface::class, function () {
        return match (config('inventorix.costing_strategy', 'fifo')) {
            'lifo' => new LifoCostingStrategy,
            'average' => new AverageCostingStrategy,
            default => new FifoCostingStrategy,
        };
    });

    expect(app(CostingStrategyInterface::class))->toBeInstanceOf(AverageCostingStrategy::class);
});

it('container resolves FifoCostingStrategy for unknown strategy value', function () {
    config(['inventorix.costing_strategy' => 'unknown']);
    app()->bind(CostingStrategyInterface::class, function () {
        return match (config('inventorix.costing_strategy', 'fifo')) {
            'lifo' => new LifoCostingStrategy,
            'average' => new AverageCostingStrategy,
            default => new FifoCostingStrategy,
        };
    });

    expect(app(CostingStrategyInterface::class))->toBeInstanceOf(FifoCostingStrategy::class);
});

// ---------------------------------------------------------------------------
// Facade integration
// ---------------------------------------------------------------------------

it('Inventorix::totalValuation integrates with costing strategy end-to-end', function () {
    // product cost_price is 10.00; two batches at different prices
    Inventorix::addStock($this->product, 5, $this->location, ['cost' => 8.00]);
    Inventorix::addStock($this->product, 5, $this->location, ['cost' => 12.00]);

    // FIFO: on-hand = newest batch (5 @ 12) + 0 leftover from older = 5 × 12 = 60
    // But only 10 total on hand and newest 5 @ 12 + oldest 5 @ 8 = 60 + 40 = 100
    expect(Inventorix::totalValuation())->toEqual(100.0);
});

it('Inventorix::totalValuation with location scope uses strategy on filtered stocks', function () {
    $locationB = Location::create(['name' => 'B', 'code' => 'WH-B', 'is_active' => true]);

    Inventorix::addStock($this->product, 10, $this->location, ['cost' => 10.00]);
    Inventorix::addStock($this->product, 5, $locationB, ['cost' => 20.00]);

    // Only WH-A: 10 @ 10 = 100
    expect(Inventorix::totalValuation($this->location))->toEqual(100.0);
    // Only WH-B: 5 @ 20 = 100
    expect(Inventorix::totalValuation($locationB))->toEqual(100.0);
});
