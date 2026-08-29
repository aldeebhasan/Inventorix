<?php

use Aldeebhasan\Inventorix\DTOs\StockOperationDto;
use Aldeebhasan\Inventorix\Enums\MovementType;
use Aldeebhasan\Inventorix\Facades\Inventorix;
use Aldeebhasan\Inventorix\Models\Location;
use Aldeebhasan\Inventorix\Models\Movement;
use Aldeebhasan\Inventorix\Tests\Support\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->locationA = Location::create(['name' => 'Warehouse A', 'code' => 'WH-A', 'is_active' => true]);
    $this->locationB = Location::create(['name' => 'Warehouse B', 'code' => 'WH-B', 'is_active' => true]);
    $this->product = Product::create(['name' => 'Widget', 'cost_price' => 0]);
});

describe('direct transfer cost propagation', function () {
    it('add movement carries the deduction cost (FIFO)', function () {
        Inventorix::addStock($this->product, 10, $this->locationA, new StockOperationDto(cost: 5.0));
        Inventorix::addStock($this->product, 10, $this->locationA, new StockOperationDto(cost: 8.0));

        Inventorix::transfer($this->product, 10, $this->locationA, $this->locationB);

        $deduct = Movement::where('type', MovementType::Deduct)
            ->where('location_id', $this->locationA->id)
            ->latest('id')->first();

        $add = Movement::where('type', MovementType::Add)
            ->where('location_id', $this->locationB->id)
            ->latest('id')->first();

        expect((float) $add->cost_per_unit)->toEqual((float) $deduct->cost_per_unit);
    });

    it('add movement carries the deduction cost (AVCO)', function () {
        config()->set('inventorix.costing_strategy', 'average');

        Inventorix::addStock($this->product, 10, $this->locationA, new StockOperationDto(cost: 4.0));

        Carbon::setTestNow(Carbon::now()->addSecond());
        Inventorix::addStock($this->product, 10, $this->locationA, new StockOperationDto(cost: 6.0));
        Carbon::setTestNow(null);

        Inventorix::transfer($this->product, 5, $this->locationA, $this->locationB);

        $deduct = Movement::where('type', MovementType::Deduct)
            ->where('location_id', $this->locationA->id)
            ->latest('id')->first();

        $add = Movement::where('type', MovementType::Add)
            ->where('location_id', $this->locationB->id)
            ->latest('id')->first();

        // Average cost: (10*4 + 10*6) / 20 = 5.0
        expect((float) $deduct->cost_per_unit)->toEqual(5.0);
        expect((float) $add->cost_per_unit)->toEqual(5.0);
    });

    it('ignores options cost in favour of computed deduction cost', function () {
        Inventorix::addStock($this->product, 10, $this->locationA, new StockOperationDto(cost: 7.0));

        // Pass a different cost in options - it should be overridden by the deduction cost
        Inventorix::transfer($this->product, 5, $this->locationA, $this->locationB, new StockOperationDto(cost: 99.0));

        $add = Movement::where('type', MovementType::Add)
            ->where('location_id', $this->locationB->id)
            ->latest('id')->first();

        expect((float) $add->cost_per_unit)->toEqual(7.0);
    });
});

describe('send/receive cost propagation', function () {
    it('receive carries the send deduction cost', function () {
        Inventorix::addStock($this->product, 10, $this->locationA, new StockOperationDto(cost: 12.0));

        $transaction = Inventorix::send($this->product, 5, $this->locationA);
        Inventorix::receive($transaction, $this->product, 5, $this->locationB);

        $deduct = Movement::where('type', MovementType::Deduct)
            ->where('location_id', $this->locationA->id)
            ->latest('id')->first();

        $add = Movement::where('type', MovementType::Add)
            ->where('location_id', $this->locationB->id)
            ->latest('id')->first();

        expect((float) $add->cost_per_unit)->toEqual((float) $deduct->cost_per_unit);
        expect((float) $add->cost_per_unit)->toEqual(12.0);
    });

    it('receive carries FIFO cost from multiple lots', function () {
        Inventorix::addStock($this->product, 10, $this->locationA, new StockOperationDto(cost: 3.0));
        Inventorix::addStock($this->product, 10, $this->locationA, new StockOperationDto(cost: 9.0));

        $transaction = Inventorix::send($this->product, 10, $this->locationA);
        Inventorix::receive($transaction, $this->product, 10, $this->locationB);

        $deduct = Movement::where('type', MovementType::Deduct)
            ->where('location_id', $this->locationA->id)
            ->latest('id')->first();

        $add = Movement::where('type', MovementType::Add)
            ->where('location_id', $this->locationB->id)
            ->latest('id')->first();

        // FIFO: consumes oldest lot @ 3.0
        expect((float) $add->cost_per_unit)->toEqual((float) $deduct->cost_per_unit);
    });

    it('receive ignores options cost in favour of deduction cost', function () {
        Inventorix::addStock($this->product, 10, $this->locationA, new StockOperationDto(cost: 15.0));

        $transaction = Inventorix::send($this->product, 5, $this->locationA);
        Inventorix::receive($transaction, $this->product, 5, $this->locationB, new StockOperationDto(cost: 99.0));

        $add = Movement::where('type', MovementType::Add)
            ->where('location_id', $this->locationB->id)
            ->latest('id')->first();

        expect((float) $add->cost_per_unit)->toEqual(15.0);
    });
});
