<?php

use Aldeebhasan\Inventorix\Contracts\CostingStrategyInterface;
use Aldeebhasan\Inventorix\Enums\MovementType;
use Aldeebhasan\Inventorix\Enums\TransactionStatus;
use Aldeebhasan\Inventorix\Enums\TransactionType;
use Aldeebhasan\Inventorix\Facades\Inventorix;
use Aldeebhasan\Inventorix\Models\Location;
use Aldeebhasan\Inventorix\Models\Movement;
use Aldeebhasan\Inventorix\Models\Stock;
use Aldeebhasan\Inventorix\Models\Transaction;
use Aldeebhasan\Inventorix\Services\ValuationService;
use Aldeebhasan\Inventorix\Strategies\Costing\AverageCostingStrategy;
use Aldeebhasan\Inventorix\Strategies\Costing\FifoCostingStrategy;
use Aldeebhasan\Inventorix\Strategies\Costing\LifoCostingStrategy;
use Aldeebhasan\Inventorix\Tests\Support\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Build an in-memory Movement instance (not persisted) with the given attributes.
 */
function fakeMovement(MovementType $type, float $quantity, ?float $cost, Carbon $createdAt): Movement
{
    $m = new Movement;
    $m->type = $type;
    $m->quantity = $quantity;
    $m->cost_per_unit = $cost;
    $m->before_quantity = 0;
    $m->after_quantity = $quantity;
    $m->created_at = $createdAt;
    $m->updated_at = $createdAt;

    return $m;
}

function fakeStock(float $quantity): Stock
{
    $s = new Stock;
    $s->quantity = $quantity;

    return $s;
}

// ---------------------------------------------------------------------------
// FifoCostingStrategy — unit tests
// ---------------------------------------------------------------------------

describe('FifoCostingStrategy', function () {
    beforeEach(fn () => $this->strategy = new FifoCostingStrategy);

    it('returns 0.0 when movements collection is empty', function () {
        $stock = fakeStock(10.0);

        expect($this->strategy->valuate($stock, collect()))->toEqual(0.0);
    });

    it('returns 0.0 when all movements have null cost_per_unit', function () {
        $stock = fakeStock(5.0);
        $movements = collect([
            fakeMovement(MovementType::Add, 5.0, null, Carbon::now()),
        ]);

        expect($this->strategy->valuate($stock, $movements))->toEqual(0.0);
    });

    it('values a single batch correctly', function () {
        $stock = fakeStock(10.0);
        $movements = collect([
            fakeMovement(MovementType::Add, 10.0, 5.0, Carbon::now()),
        ]);

        // 10 units × $5.00 = $50.00
        expect($this->strategy->valuate($stock, $movements))->toEqual(50.0);
    });

    it('values on-hand stock using newest batches (FIFO on-hand = latest purchase)', function () {
        // Total inbound: 10 old @ $5 + 10 new @ $8 = 20 units bought
        // Deducted 10 (oldest batch sold first)
        // On-hand: 10 units — these are the NEW batch @ $8
        $stock = fakeStock(10.0);
        $t1 = Carbon::now()->subHour();
        $t2 = Carbon::now();

        $movements = collect([
            fakeMovement(MovementType::Add, 10.0, 5.0, $t1), // older — sold first under FIFO
            fakeMovement(MovementType::Add, 10.0, 8.0, $t2), // newer — still on hand
        ]);

        // FIFO on-hand = newest batch; 10 × $8.00 = $80.00
        expect($this->strategy->valuate($stock, $movements))->toEqual(80.0);
    });

    it('spans multiple newest batches when on-hand quantity exceeds single batch', function () {
        // Bought 5 @ $4 (oldest), 8 @ $7 (mid), 6 @ $10 (newest)
        // Total bought = 19, current stock = 14 → oldest 5 were sold
        $stock = fakeStock(14.0);
        $t1 = Carbon::now()->subHours(3);
        $t2 = Carbon::now()->subHour();
        $t3 = Carbon::now();

        $movements = collect([
            fakeMovement(MovementType::Add, 5.0, 4.0, $t1),  // sold (oldest)
            fakeMovement(MovementType::Add, 8.0, 7.0, $t2),  // on hand
            fakeMovement(MovementType::Add, 6.0, 10.0, $t3), // on hand
        ]);

        // On-hand: 6 × $10 + 8 × $7 = 60 + 56 = $116
        expect($this->strategy->valuate($stock, $movements))->toEqual(116.0);
    });

    it('includes TransferIn movements as inbound', function () {
        $stock = fakeStock(10.0);
        $movements = collect([
            fakeMovement(MovementType::TransferIn, 10.0, 6.0, Carbon::now()),
        ]);

        expect($this->strategy->valuate($stock, $movements))->toEqual(60.0);
    });

    it('includes positive Adjustment movements as inbound', function () {
        $stock = fakeStock(5.0);
        $movements = collect([
            fakeMovement(MovementType::Adjustment, 5.0, 9.0, Carbon::now()),
        ]);

        expect($this->strategy->valuate($stock, $movements))->toEqual(45.0);
    });

    it('excludes negative Adjustment movements', function () {
        $stock = fakeStock(5.0);
        $movements = collect([
            fakeMovement(MovementType::Add, 10.0, 5.0, Carbon::now()->subMinute()),
            fakeMovement(MovementType::Adjustment, -5.0, 5.0, Carbon::now()),
        ]);

        // Only the Add of 10 is inbound; stock is 5 → value = 5 × $5 = $25
        expect($this->strategy->valuate($stock, $movements))->toEqual(25.0);
    });

    it('excludes Deduct and Fulfillment movement types', function () {
        $stock = fakeStock(5.0);
        $movements = collect([
            fakeMovement(MovementType::Add, 10.0, 5.0, Carbon::now()->subMinute()),
            fakeMovement(MovementType::Deduct, 5.0, 5.0, Carbon::now()),
        ]);

        // Only Add is inbound; stock = 5 → value = 5 × $5 = $25
        expect($this->strategy->valuate($stock, $movements))->toEqual(25.0);
    });

    it('skips movements with null cost_per_unit even if type is inbound', function () {
        $stock = fakeStock(10.0);
        $t1 = Carbon::now()->subMinute();
        $t2 = Carbon::now();

        $movements = collect([
            fakeMovement(MovementType::Add, 5.0, null, $t1), // no cost data — skipped
            fakeMovement(MovementType::Add, 10.0, 8.0, $t2), // costed
        ]);

        // Only the $8 batch contributes; stock = 10, covers the full $8 batch
        expect($this->strategy->valuate($stock, $movements))->toEqual(80.0);
    });

    it('returns partial value when costed batches do not cover full stock quantity', function () {
        // 15 on hand but only 10 units have cost records
        $stock = fakeStock(15.0);
        $movements = collect([
            fakeMovement(MovementType::Add, 10.0, 6.0, Carbon::now()),
        ]);

        // Only covers 10 units → 10 × $6 = $60; remaining 5 have no cost data
        expect($this->strategy->valuate($stock, $movements))->toEqual(60.0);
    });
});

// ---------------------------------------------------------------------------
// LifoCostingStrategy — unit tests
// ---------------------------------------------------------------------------

describe('LifoCostingStrategy', function () {
    beforeEach(fn () => $this->strategy = new LifoCostingStrategy);

    it('returns 0.0 when movements collection is empty', function () {
        $stock = fakeStock(10.0);

        expect($this->strategy->valuate($stock, collect()))->toEqual(0.0);
    });

    it('returns 0.0 when all movements have null cost_per_unit', function () {
        $stock = fakeStock(5.0);
        $movements = collect([
            fakeMovement(MovementType::Add, 5.0, null, Carbon::now()),
        ]);

        expect($this->strategy->valuate($stock, $movements))->toEqual(0.0);
    });

    it('values a single batch correctly', function () {
        $stock = fakeStock(10.0);
        $movements = collect([
            fakeMovement(MovementType::Add, 10.0, 5.0, Carbon::now()),
        ]);

        expect($this->strategy->valuate($stock, $movements))->toEqual(50.0);
    });

    it('values on-hand stock using oldest batches (LIFO on-hand = earliest purchase)', function () {
        // Total bought: 10 old @ $5 + 10 new @ $8 = 20 units
        // Deducted 10 (newest sold first under LIFO)
        // On-hand: 10 units — these are the OLD batch @ $5
        $stock = fakeStock(10.0);
        $t1 = Carbon::now()->subHour();
        $t2 = Carbon::now();

        $movements = collect([
            fakeMovement(MovementType::Add, 10.0, 5.0, $t1), // older — still on hand under LIFO
            fakeMovement(MovementType::Add, 10.0, 8.0, $t2), // newer — sold first under LIFO
        ]);

        // LIFO on-hand = oldest batch; 10 × $5.00 = $50.00
        expect($this->strategy->valuate($stock, $movements))->toEqual(50.0);
    });

    it('spans multiple oldest batches when on-hand quantity exceeds single batch', function () {
        // Bought 5 @ $4 (oldest), 8 @ $7 (mid), 6 @ $10 (newest)
        // Total = 19; stock = 14 → newest 5 were sold (LIFO)
        $stock = fakeStock(14.0);
        $t1 = Carbon::now()->subHours(3);
        $t2 = Carbon::now()->subHour();
        $t3 = Carbon::now();

        $movements = collect([
            fakeMovement(MovementType::Add, 5.0, 4.0, $t1),  // on hand
            fakeMovement(MovementType::Add, 8.0, 7.0, $t2),  // on hand
            fakeMovement(MovementType::Add, 6.0, 10.0, $t3), // sold (newest)
        ]);

        // On-hand: 5 × $4 + 8 × $7 + 1 × $10 = 20 + 56 + 10 = $86
        expect($this->strategy->valuate($stock, $movements))->toEqual(86.0);
    });

    it('produces a different result than FIFO when costs vary across batches', function () {
        $stock = fakeStock(10.0);
        $t1 = Carbon::now()->subHour();
        $t2 = Carbon::now();

        $movements = collect([
            fakeMovement(MovementType::Add, 10.0, 5.0, $t1),
            fakeMovement(MovementType::Add, 10.0, 8.0, $t2),
        ]);

        $fifo = (new FifoCostingStrategy)->valuate($stock, $movements);
        $lifo = (new LifoCostingStrategy)->valuate($stock, $movements);

        expect($fifo)->toEqual(80.0)  // newest on hand
            ->and($lifo)->toEqual(50.0)  // oldest on hand
            ->and($fifo)->not->toEqual($lifo);
    });
});

// ---------------------------------------------------------------------------
// AverageCostingStrategy — unit tests
// ---------------------------------------------------------------------------

describe('AverageCostingStrategy', function () {
    beforeEach(fn () => $this->strategy = new AverageCostingStrategy);

    it('returns 0.0 when movements collection is empty', function () {
        $stock = fakeStock(10.0);

        expect($this->strategy->valuate($stock, collect()))->toEqual(0.0);
    });

    it('returns 0.0 when all movements have null cost_per_unit', function () {
        $stock = fakeStock(5.0);
        $movements = collect([
            fakeMovement(MovementType::Add, 5.0, null, Carbon::now()),
        ]);

        expect($this->strategy->valuate($stock, $movements))->toEqual(0.0);
    });

    it('computes weighted average from a single batch', function () {
        $stock = fakeStock(10.0);
        $movements = collect([
            fakeMovement(MovementType::Add, 10.0, 5.0, Carbon::now()),
        ]);

        // avg = 5.0; value = 10 × $5 = $50
        expect($this->strategy->valuate($stock, $movements))->toEqual(50.0);
    });

    it('computes weighted average across multiple batches', function () {
        // 10 units @ $4 + 10 units @ $6 → total cost $100, total qty 20
        // avg = $5; if 10 on hand → value = $50
        $stock = fakeStock(10.0);
        $movements = collect([
            fakeMovement(MovementType::Add, 10.0, 4.0, Carbon::now()->subHour()),
            fakeMovement(MovementType::Add, 10.0, 6.0, Carbon::now()),
        ]);

        expect($this->strategy->valuate($stock, $movements))->toEqual(50.0);
    });

    it('produces the same result regardless of movement order', function () {
        $stock = fakeStock(10.0);
        $t1 = Carbon::now()->subHour();
        $t2 = Carbon::now();

        $movementsAsc = collect([
            fakeMovement(MovementType::Add, 10.0, 4.0, $t1),
            fakeMovement(MovementType::Add, 10.0, 6.0, $t2),
        ]);

        $movementsDesc = collect([
            fakeMovement(MovementType::Add, 10.0, 6.0, $t2),
            fakeMovement(MovementType::Add, 10.0, 4.0, $t1),
        ]);

        expect($this->strategy->valuate($stock, $movementsAsc))
            ->toEqual($this->strategy->valuate($stock, $movementsDesc));
    });

    it('ignores movements with null cost_per_unit in the average calculation', function () {
        // 5 units at $10 (costed) + 5 units null (uncosted)
        // avg = $10; stock = 5 → value = 5 × $10 = $50
        $stock = fakeStock(5.0);
        $movements = collect([
            fakeMovement(MovementType::Add, 5.0, 10.0, Carbon::now()->subHour()),
            fakeMovement(MovementType::Add, 5.0, null, Carbon::now()),
        ]);

        expect($this->strategy->valuate($stock, $movements))->toEqual(50.0);
    });

    it('gives a result between FIFO and LIFO when batch costs differ', function () {
        $stock = fakeStock(10.0);
        $t1 = Carbon::now()->subHour();
        $t2 = Carbon::now();

        $movements = collect([
            fakeMovement(MovementType::Add, 10.0, 5.0, $t1),
            fakeMovement(MovementType::Add, 10.0, 8.0, $t2),
        ]);

        $fifo = (new FifoCostingStrategy)->valuate($stock, $movements);   // 80
        $lifo = (new LifoCostingStrategy)->valuate($stock, $movements);   // 50
        $avg = $this->strategy->valuate($stock, $movements);              // 65

        expect($avg)->toBeGreaterThan($lifo)
            ->and($avg)->toBeLessThan($fifo)
            ->and($avg)->toEqual(65.0);
    });
});

// ---------------------------------------------------------------------------
// Service Provider — strategy binding
// ---------------------------------------------------------------------------

describe('CostingStrategyInterface binding', function () {
    it('resolves FifoCostingStrategy when costing_strategy is fifo', function () {
        config()->set('inventorix.costing_strategy', 'fifo');
        app()->forgetInstance(CostingStrategyInterface::class);

        expect(app(CostingStrategyInterface::class))->toBeInstanceOf(FifoCostingStrategy::class);
    });

    it('resolves LifoCostingStrategy when costing_strategy is lifo', function () {
        config()->set('inventorix.costing_strategy', 'lifo');
        app()->forgetInstance(CostingStrategyInterface::class);

        expect(app(CostingStrategyInterface::class))->toBeInstanceOf(LifoCostingStrategy::class);
    });

    it('resolves AverageCostingStrategy when costing_strategy is average', function () {
        config()->set('inventorix.costing_strategy', 'average');
        app()->forgetInstance(CostingStrategyInterface::class);

        expect(app(CostingStrategyInterface::class))->toBeInstanceOf(AverageCostingStrategy::class);
    });

    it('defaults to FifoCostingStrategy for unknown strategy values', function () {
        config()->set('inventorix.costing_strategy', 'unknown');
        app()->forgetInstance(CostingStrategyInterface::class);

        expect(app(CostingStrategyInterface::class))->toBeInstanceOf(FifoCostingStrategy::class);
    });
});

// ---------------------------------------------------------------------------
// Integration tests — addStock stores cost_per_unit on movements
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
        Inventorix::addStock($this->product, 5, $this->location, ['cost' => 12.50]);

        $movement = Movement::first();
        expect((float) $movement->cost_per_unit)->toEqual(12.5);
    });

    it('addStock records null cost_per_unit when stockable has no cost_price', function () {
        // No cost_price provided → DB default is 0, treated as "no cost data"
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
        Inventorix::adjustStock($this->product, 15, $this->location); // +5 positive
        Inventorix::adjustStock($this->product, 10, $this->location); // -5 negative

        $adjustments = Movement::where('type', MovementType::Adjustment->value)->get();
        expect($adjustments)->toHaveCount(2);

        $positive = $adjustments->firstWhere('quantity', '>', 0);
        $negative = $adjustments->firstWhere('quantity', '<', 0);

        expect((float) $positive->cost_per_unit)->toEqual(10.0)
            ->and($negative->cost_per_unit)->toBeNull();
    });
});

// ---------------------------------------------------------------------------
// Integration tests — totalValuation uses the configured strategy
// ---------------------------------------------------------------------------

describe('totalValuation with costing strategies', function () {
    beforeEach(function () {
        $this->location = Location::create(['name' => 'Warehouse A', 'code' => 'WH-A', 'is_active' => true]);
    });

    it('uses FIFO by default (config default is fifo)', function () {
        config()->set('inventorix.costing_strategy', 'fifo');

        $product = Product::create(['name' => 'Widget', 'cost_price' => 0]);

        // Add two batches at different costs
        Inventorix::addStock($product, 10, $this->location, ['cost' => 5.0]);

        // Move time forward so second batch has a later created_at
        Carbon::setTestNow(Carbon::now()->addSecond());
        Inventorix::addStock($product, 10, $this->location, ['cost' => 8.0]);
        Carbon::setTestNow(null);

        // Deduct 10 (FIFO: oldest batch removed first)
        Inventorix::deductStock($product, 10, $this->location);

        // On-hand: 10 units from newest batch @ $8 = $80
        expect(Inventorix::totalValuation())->toEqual(80.0);
    });

    it('uses LIFO when configured', function () {
        $product = Product::create(['name' => 'Widget', 'cost_price' => 0]);

        Inventorix::addStock($product, 10, $this->location, ['cost' => 5.0]);

        Carbon::setTestNow(Carbon::now()->addSecond());
        Inventorix::addStock($product, 10, $this->location, ['cost' => 8.0]);
        Carbon::setTestNow(null);

        Inventorix::deductStock($product, 10, $this->location);

        // Instantiate ValuationService directly with LIFO to avoid singleton issues
        $service = new ValuationService(new LifoCostingStrategy);

        // On-hand: 10 units from oldest batch @ $5 = $50
        expect($service->totalValuation())->toEqual(50.0);
    });

    it('uses weighted average when configured', function () {
        $product = Product::create(['name' => 'Widget', 'cost_price' => 0]);

        // 10 @ $4 + 10 @ $6 → avg = $5; 20 on hand → $100
        Inventorix::addStock($product, 10, $this->location, ['cost' => 4.0]);
        Inventorix::addStock($product, 10, $this->location, ['cost' => 6.0]);

        $service = new ValuationService(new AverageCostingStrategy);

        expect($service->totalValuation())->toEqual(100.0);
    });

    it('falls back to flat cost_price when movements have no cost_per_unit', function () {
        // Directly insert a movement with null cost_per_unit to simulate legacy data
        $product = Product::create(['name' => 'Legacy', 'cost_price' => 7.0]);
        $stock = Stock::create([
            'stockable_type' => get_class($product),
            'stockable_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity' => 5,
            'reserved_quantity' => 0,
        ]);
        $transaction = Transaction::create([
            'type' => TransactionType::Manual,
            'status' => TransactionStatus::Committed,
        ]);

        Movement::create([
            'stockable_type' => get_class($product),
            'stockable_id' => $product->id,
            'location_id' => $this->location->id,
            'transaction_id' => $transaction->id,
            'type' => MovementType::Add,
            'quantity' => 5,
            'cost_per_unit' => null,
            'before_quantity' => 0,
            'after_quantity' => 5,
        ]);

        // Falls back to flat cost_price: 5 × $7.00 = $35
        expect(Inventorix::totalValuation())->toEqual(35.0);
    });

    it('totalValuation returns 0.0 with strategy when no stocks exist', function () {
        config()->set('inventorix.costing_strategy', 'fifo');
        expect(Inventorix::totalValuation())->toEqual(0.0);
    });

    it('totalValuation with strategy filters correctly by location', function () {
        config()->set('inventorix.costing_strategy', 'fifo');
        $locationB = Location::create(['name' => 'Warehouse B', 'code' => 'WH-B', 'is_active' => true]);
        $product = Product::create(['name' => 'Widget', 'cost_price' => 0]);

        Inventorix::addStock($product, 10, $this->location, ['cost' => 5.0]);
        Inventorix::addStock($product, 5, $locationB, ['cost' => 8.0]);

        // Location A: 10 × $5 = $50
        expect(Inventorix::totalValuation($this->location))->toEqual(50.0);

        // Location B: 5 × $8 = $40
        expect(Inventorix::totalValuation($locationB))->toEqual(40.0);

        // All: $90
        expect(Inventorix::totalValuation())->toEqual(90.0);
    });
});
