<?php

use Aldeebhasan\Inventorix\DTOs\StockOperationDto;
use Aldeebhasan\Inventorix\Enums\MovementType;
use Aldeebhasan\Inventorix\Facades\Inventorix;
use Aldeebhasan\Inventorix\Models\Location;
use Aldeebhasan\Inventorix\Models\Movement;
use Aldeebhasan\Inventorix\Models\MovementSource;
use Aldeebhasan\Inventorix\Services\CostingService;
use Aldeebhasan\Inventorix\Tests\Support\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function setup(): array
{
    $location = Location::create(['name' => 'Warehouse A', 'code' => 'WH-A', 'is_active' => true]);
    $product = Product::create(['name' => 'Widget', 'cost_price' => 0]);

    return [$location, $product];
}

// ---------------------------------------------------------------------------
// FIFO linking
// ---------------------------------------------------------------------------

describe('CostingService::linkSources — FIFO', function () {
    beforeEach(function () {
        config()->set('inventorix.costing_strategy', 'fifo');
        [$this->location, $this->product] = setup();
    });

    it('creates no sources when no costed inbound movements exist', function () {
        Inventorix::deductStock($this->product, 5, $this->location, new StockOperationDto(allowNegative: true));

        expect(MovementSource::count())->toBe(0);
    });

    it('links deduction to a single lot and stores cost_per_unit (FIFO)', function () {
        Inventorix::addStock($this->product, 20, $this->location, new StockOperationDto(cost: 10.0));
        Inventorix::deductStock($this->product, 10, $this->location);

        $deduction = Movement::where('type', MovementType::Deduct->value)->first();

        expect(MovementSource::count())->toBe(1)
            ->and((float) MovementSource::first()->quantity)->toEqual(10.0)
            ->and((float) $deduction->cost_per_unit)->toEqual(10.0);
    });

    it('consumes oldest lot first, then spills into newer lot (FIFO)', function () {
        // Lot 1: 20 @ $10 (older)
        Inventorix::addStock($this->product, 20, $this->location, new StockOperationDto(cost: 10.0));
        // Lot 2: 20 @ $20 (newer) — advance time so created_at differs
        Carbon::setTestNow(now()->addSecond());
        Inventorix::addStock($this->product, 20, $this->location, new StockOperationDto(cost: 20.0));
        Carbon::setTestNow(null);

        Inventorix::deductStock($this->product, 25, $this->location);

        $deduction = Movement::where('type', MovementType::Deduct->value)->first();
        $lot1 = Movement::where('type', MovementType::Add->value)->orderBy('id')->first();
        $lot2 = Movement::where('type', MovementType::Add->value)->orderBy('id')->skip(1)->first();

        $sources = MovementSource::where('deduction_movement_id', $deduction->id)
            ->orderBy('source_movement_id')
            ->get();

        expect($sources)->toHaveCount(2);

        $fromLot1 = $sources->firstWhere('source_movement_id', $lot1->id);
        $fromLot2 = $sources->firstWhere('source_movement_id', $lot2->id);

        expect((float) $fromLot1->quantity)->toEqual(20.0)
            ->and((float) $fromLot2->quantity)->toEqual(5.0);

        // Stored cost: (20×10 + 5×20) / 25 = 300/25 = 12.00
        expect(round((float) $deduction->cost_per_unit, 4))->toEqual(12.0);
    });

    it('second deduction respects lot depletion from first (FIFO)', function () {
        Inventorix::addStock($this->product, 20, $this->location, new StockOperationDto(cost: 10.0));
        Carbon::setTestNow(now()->addSecond());
        Inventorix::addStock($this->product, 20, $this->location, new StockOperationDto(cost: 20.0));
        Carbon::setTestNow(null);

        // First deduction consumes all of Lot 1 and 5 from Lot 2
        Inventorix::deductStock($this->product, 25, $this->location);

        // Second deduction should only pull from Lot 2's remaining 15
        Carbon::setTestNow(now()->addSeconds(2));
        Inventorix::deductStock($this->product, 10, $this->location);
        Carbon::setTestNow(null);

        $deductions = Movement::where('type', MovementType::Deduct->value)->orderBy('id')->get();
        $lot2 = Movement::where('type', MovementType::Add->value)->orderBy('id')->skip(1)->first();

        $secondSources = MovementSource::where('deduction_movement_id', $deductions[1]->id)->get();

        expect($secondSources)->toHaveCount(1)
            ->and((int) $secondSources->first()->source_movement_id)->toBe((int) $lot2->id)
            ->and((float) $secondSources->first()->quantity)->toEqual(10.0);

        expect((float) $deductions[1]->cost_per_unit)->toEqual(20.0);
    });

    it('stored cost is stale until recomputeAndStore() is called after a source correction', function () {
        Inventorix::addStock($this->product, 20, $this->location, new StockOperationDto(cost: 10.0));
        Carbon::setTestNow(now()->addSecond());
        Inventorix::addStock($this->product, 20, $this->location, new StockOperationDto(cost: 20.0));
        Carbon::setTestNow(null);

        Inventorix::deductStock($this->product, 25, $this->location);

        $deduction = Movement::where('type', MovementType::Deduct->value)->first();

        // Stored at deduction time: (20×10 + 5×20) / 25 = 12.00
        expect(round((float) $deduction->cost_per_unit, 4))->toEqual(12.0);

        // Correct Lot 1's cost to $12.00
        $lot1 = Movement::where('type', MovementType::Add->value)->orderBy('id')->first();
        $lot1->update(['cost_per_unit' => 12.0]);

        // After correction + explicit recompute: (20×12 + 5×20) / 25 = 340/25 = 13.60
        $newCost = app(CostingService::class)->recomputeAndStore($deduction);
        expect(round($newCost, 4))->toEqual(13.6)
            ->and(round((float) $deduction->fresh()->cost_per_unit, 4))->toEqual(13.6);
    });
});

// ---------------------------------------------------------------------------
// LIFO linking
// ---------------------------------------------------------------------------

describe('CostingService::linkSources — LIFO', function () {
    beforeEach(function () {
        config()->set('inventorix.costing_strategy', 'lifo');
        [$this->location, $this->product] = setup();
    });

    it('consumes newest lot first, then spills into older lot (LIFO)', function () {
        Inventorix::addStock($this->product, 20, $this->location, new StockOperationDto(cost: 10.0));
        Carbon::setTestNow(now()->addSecond());
        Inventorix::addStock($this->product, 20, $this->location, new StockOperationDto(cost: 20.0));
        Carbon::setTestNow(null);

        Inventorix::deductStock($this->product, 25, $this->location);

        $deduction = Movement::where('type', MovementType::Deduct->value)->first();
        $lot1 = Movement::where('type', MovementType::Add->value)->orderBy('id')->first();
        $lot2 = Movement::where('type', MovementType::Add->value)->orderBy('id')->skip(1)->first();

        $sources = MovementSource::where('deduction_movement_id', $deduction->id)->get();

        $fromLot1 = $sources->firstWhere('source_movement_id', $lot1->id);
        $fromLot2 = $sources->firstWhere('source_movement_id', $lot2->id);

        // LIFO: newest (Lot 2 @ $20) consumed first → 20 from Lot 2, 5 from Lot 1
        expect((float) $fromLot2->quantity)->toEqual(20.0)
            ->and((float) $fromLot1->quantity)->toEqual(5.0);

        // Stored cost: (20×20 + 5×10) / 25 = 450/25 = 18.00
        expect(round((float) $deduction->cost_per_unit, 4))->toEqual(18.0);
    });
});

// ---------------------------------------------------------------------------
// AVERAGE linking
// ---------------------------------------------------------------------------

describe('CostingService::linkSources — AVERAGE', function () {
    beforeEach(function () {
        config()->set('inventorix.costing_strategy', 'average');
        [$this->location, $this->product] = setup();
    });

    it('distributes deduction proportionally across all lots (AVERAGE)', function () {
        Inventorix::addStock($this->product, 20, $this->location, new StockOperationDto(cost: 10.0));
        Inventorix::addStock($this->product, 20, $this->location, new StockOperationDto(cost: 20.0));

        Inventorix::deductStock($this->product, 20, $this->location);

        $deduction = Movement::where('type', MovementType::Deduct->value)->first();
        $sources = MovementSource::where('deduction_movement_id', $deduction->id)->get();

        expect($sources->count())->toBeGreaterThanOrEqual(1);

        $totalSourceQty = $sources->sum(fn ($s) => (float) $s->quantity);
        expect(round($totalSourceQty, 4))->toEqual(20.0);

        // Stored cost should be the weighted average: (10×10 + 10×20)/20 = 15.00
        expect(round((float) $deduction->cost_per_unit, 2))->toEqual(15.0);
    });
});

// ---------------------------------------------------------------------------
// Fulfillment (ReservationService)
// ---------------------------------------------------------------------------

describe('CostingService::linkSources — Fulfillment', function () {
    beforeEach(function () {
        config()->set('inventorix.costing_strategy', 'fifo');
        [$this->location, $this->product] = setup();
    });

    it('links sources for fulfillment movements', function () {
        Inventorix::addStock($this->product, 20, $this->location, new StockOperationDto(cost: 15.0));
        $reservation = Inventorix::reserve($this->product, 10, $this->location);
        Inventorix::fulfillReservation($reservation);

        $fulfillment = Movement::where('type', MovementType::Deduct->value)->first();

        expect(MovementSource::where('deduction_movement_id', $fulfillment->id)->count())->toBe(1)
            ->and((float) $fulfillment->cost_per_unit)->toEqual(15.0);
    });
});

// ---------------------------------------------------------------------------
// computeDeductionCost — returns null when no sources
// ---------------------------------------------------------------------------

describe('CostingService::computeDeductionCost', function () {
    beforeEach(function () {
        config()->set('inventorix.costing_strategy', 'fifo');
        [$this->location, $this->product] = setup();
    });

    it('returns null when movement has no sources', function () {
        Inventorix::deductStock($this->product, 5, $this->location, new StockOperationDto(allowNegative: true));

        $deduction = Movement::where('type', MovementType::Deduct->value)->first();

        expect($deduction->cost_per_unit)->toBeNull();
        expect(app(CostingService::class)->recomputeAndStore($deduction))->toBeNull();
    });
});
