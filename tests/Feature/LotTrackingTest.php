<?php

use Aldeebhasan\Inventorix\DTOs\StockOperationDto;
use Aldeebhasan\Inventorix\Enums\MovementType;
use Aldeebhasan\Inventorix\Facades\Inventorix;
use Aldeebhasan\Inventorix\Models\Location;
use Aldeebhasan\Inventorix\Models\Movement;
use Aldeebhasan\Inventorix\Models\MovementSource;
use Aldeebhasan\Inventorix\Tests\Support\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Group 1 — Lot metadata stored on movements
// ---------------------------------------------------------------------------

describe('Lot metadata stored on movements', function () {
    beforeEach(function () {
        $this->location = Location::create(['name' => 'Warehouse A', 'code' => 'WH-A', 'is_active' => true]);
        $this->product = Product::create(['name' => 'Widget', 'cost_price' => 10.00]);
    });

    it('lot_reference is stored on Add movement', function () {
        Inventorix::addStock($this->product, 10, $this->location, new StockOperationDto(
            lotReference: 'BATCH-001',
        ));

        $movement = Movement::where('type', MovementType::Add->value)->first();
        expect($movement->lot_reference)->toBe('BATCH-001');
    });

    it('expires_at is stored on Add movement', function () {
        Inventorix::addStock($this->product, 10, $this->location, new StockOperationDto(
            expiresAt: Carbon::parse('2027-01-01'),
        ));

        $movement = Movement::where('type', MovementType::Add->value)->first();
        expect($movement->expires_at->format('Y-m-d'))->toBe('2027-01-01');
    });

    it('external_reference is stored on Add and Deduct movements', function () {
        Inventorix::addStock($this->product, 20, $this->location, new StockOperationDto(
            externalReference: 'PO-12345',
        ));
        Inventorix::deductStock($this->product, 5, $this->location, new StockOperationDto(
            externalReference: 'PO-12345',
        ));

        $addMovement = Movement::where('type', MovementType::Add->value)->first();
        $deductMovement = Movement::where('type', MovementType::Deduct->value)->first();

        expect($addMovement->external_reference)->toBe('PO-12345');
        expect($deductMovement->external_reference)->toBe('PO-12345');
    });

    it('lot metadata fields are null when not provided', function () {
        Inventorix::addStock($this->product, 10, $this->location);

        $movement = Movement::where('type', MovementType::Add->value)->first();
        expect($movement->lot_reference)->toBeNull();
        expect($movement->expires_at)->toBeNull();
        expect($movement->external_reference)->toBeNull();
    });
});

// ---------------------------------------------------------------------------
// Group 2 — FEFO costing strategy
// ---------------------------------------------------------------------------

describe('FEFO costing strategy', function () {
    beforeEach(function () {
        config()->set('inventorix.costing_strategy', 'fefo');
        $this->location = Location::create(['name' => 'Warehouse A', 'code' => 'WH-A', 'is_active' => true]);
        $this->product = Product::create(['name' => 'Widget', 'cost_price' => 0]);
    });

    it('FEFO consumes nearest-expiry lot first', function () {
        // Lot A: expires later — should be consumed last
        Inventorix::addStock($this->product, 10, $this->location, new StockOperationDto(
            cost: 5.0,
            expiresAt: Carbon::parse('2027-12-31'),
        ));

        // Lot B: expires sooner — should be consumed first
        Carbon::setTestNow(Carbon::now()->addSecond());
        Inventorix::addStock($this->product, 10, $this->location, new StockOperationDto(
            cost: 8.0,
            expiresAt: Carbon::parse('2026-06-01'),
        ));
        Carbon::setTestNow(null);

        Inventorix::deductStock($this->product, 10, $this->location);

        $deduction = Movement::where('type', MovementType::Deduct->value)->first();
        // Lot B (cost 8.0, nearest expiry 2026-06-01) consumed first
        expect((float) $deduction->cost_per_unit)->toEqual(8.0);

        // Verify source links to lot B's Add movement
        $lotBMovement = Movement::where('type', MovementType::Add->value)
            ->where('cost_per_unit', 8.0)
            ->first();
        $source = MovementSource::where('deduction_movement_id', $deduction->id)->first();
        expect($source->source_movement_id)->toBe($lotBMovement->id);
    });

    it('FEFO partially consumes nearest-expiry lot then moves to next', function () {
        // Lot A: expires later, 10 units, cost 5.0
        Inventorix::addStock($this->product, 10, $this->location, new StockOperationDto(
            cost: 5.0,
            expiresAt: Carbon::parse('2027-12-31'),
        ));

        // Lot B: expires sooner, 5 units, cost 8.0
        Carbon::setTestNow(Carbon::now()->addSecond());
        Inventorix::addStock($this->product, 5, $this->location, new StockOperationDto(
            cost: 8.0,
            expiresAt: Carbon::parse('2026-06-01'),
        ));
        Carbon::setTestNow(null);

        // Deduct 8: FEFO consumes lot B (5 units, nearest expiry 2026-06-01) first, then 3 from lot A
        Inventorix::deductStock($this->product, 8, $this->location);

        $deduction = Movement::where('type', MovementType::Deduct->value)->first();
        // Cost = (5×8.0 + 3×5.0) / 8 = (40 + 15) / 8 = 55/8 = 6.875
        expect((float) $deduction->cost_per_unit)->toEqual(6.875);

        // Two source links should have been created (one per lot consumed)
        $sources = MovementSource::where('deduction_movement_id', $deduction->id)->get();
        expect($sources)->toHaveCount(2);
    });

    it('FEFO treats null expires_at lots as last resort', function () {
        // Lot A: no expiry, cost 5.0 — added first
        Inventorix::addStock($this->product, 10, $this->location, new StockOperationDto(
            cost: 5.0,
        ));

        // Lot B: expires soonest, cost 8.0 — added second
        Carbon::setTestNow(Carbon::now()->addSecond());
        Inventorix::addStock($this->product, 10, $this->location, new StockOperationDto(
            cost: 8.0,
            expiresAt: Carbon::parse('2026-06-01'),
        ));
        Carbon::setTestNow(null);

        // Deduct 10: lot B (has expiry) consumed first despite being added second
        Inventorix::deductStock($this->product, 10, $this->location);

        $deduction = Movement::where('type', MovementType::Deduct->value)->first();
        expect((float) $deduction->cost_per_unit)->toEqual(8.0);

        // Verify source links to lot B (the one with expiry)
        $lotB = Movement::where('type', MovementType::Add->value)
            ->whereNotNull('expires_at')
            ->first();
        $source = MovementSource::where('deduction_movement_id', $deduction->id)->first();
        expect($source->source_movement_id)->toBe($lotB->id);
    });

    it('FEFO with only non-expiring lots falls back to FIFO order', function () {
        // Lot A: no expires_at, cost 5.0 — added first
        Inventorix::addStock($this->product, 10, $this->location, new StockOperationDto(
            cost: 5.0,
        ));

        // Lot B: no expires_at, cost 8.0 — added second (later created_at)
        Carbon::setTestNow(Carbon::now()->addSecond());
        Inventorix::addStock($this->product, 10, $this->location, new StockOperationDto(
            cost: 8.0,
        ));
        Carbon::setTestNow(null);

        // Deduct 10: no expiry on either, falls back to created_at ASC → lot A consumed first
        Inventorix::deductStock($this->product, 10, $this->location);

        $deduction = Movement::where('type', MovementType::Deduct->value)->first();
        expect((float) $deduction->cost_per_unit)->toEqual(5.0);

        // Verify source links to lot A (cost 5.0, earliest created_at)
        $lotA = Movement::where('type', MovementType::Add->value)
            ->orderBy('id', 'asc')
            ->first();
        $source = MovementSource::where('deduction_movement_id', $deduction->id)->first();
        expect($source->source_movement_id)->toBe($lotA->id);
    });
});
