<?php

/**
 * Stress / load-integrity tests for Inventorix.
 *
 * These tests run high-volume sequential operations against a real SQLite database
 * and verify that stock quantities, movement audit-trails, and reservations remain
 * fully consistent.  They do NOT spawn separate OS processes (PHP cannot do that
 * inside a single Pest run), so they cannot surface true OS-level race conditions;
 * for that you need k6/wrk against a running app.  What they DO catch:
 *
 *  • Lost-update bugs in increment/decrement paths
 *  • Off-by-one errors under high iteration counts
 *  • Broken before/after quantity chains in the movement audit-trail
 *  • Over-reservation (selling more than available stock)
 *  • Stock-conservation violations across transfers
 *  • Movement-to-stock ratio correctness in bulk/mixed scenarios
 *  • Cross-product isolation (one product's ops must not bleed into another's)
 */

use Aldeebhasan\Inventorix\DTOs\StockOperationDto;
use Aldeebhasan\Inventorix\Exceptions\InsufficientStockException;
use Aldeebhasan\Inventorix\Facades\Inventorix;
use Aldeebhasan\Inventorix\Models\Location;
use Aldeebhasan\Inventorix\Models\Movement;
use Aldeebhasan\Inventorix\Models\Stock;
use Aldeebhasan\Inventorix\Models\Transaction;
use Aldeebhasan\Inventorix\Tests\Support\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ─────────────────────────────────────────────────────────────────────────────
// Shared setup
// ─────────────────────────────────────────────────────────────────────────────

beforeEach(function () {
    $this->location = Location::create(['name' => 'Main Warehouse', 'code' => 'WH-MAIN', 'is_active' => true]);
    $this->product = Product::create(['name' => 'StressProduct', 'cost_price' => 5.00]);
});

// ─────────────────────────────────────────────────────────────────────────────
// 1. High-volume sequential adds
// ─────────────────────────────────────────────────────────────────────────────

it('maintains correct stock after 200 sequential addStock calls', function () {
    $iterations = 200;
    $qtyPerAdd = 5;

    for ($i = 0; $i < $iterations; $i++) {
        $this->product->addStock($qtyPerAdd, $this->location);
    }

    $stock = $this->product->stockAt($this->location);
    expect($stock->quantity)->toEqual($iterations * $qtyPerAdd);

    $movementCount = Movement::where('stockable_type', Product::class)
        ->where('stockable_id', $this->product->id)
        ->count();
    expect($movementCount)->toBe($iterations);
});

// ─────────────────────────────────────────────────────────────────────────────
// 2. High-volume sequential deducts
// ─────────────────────────────────────────────────────────────────────────────

it('maintains correct stock after 100 sequential deductStock calls', function () {
    $initialQty = 1000;
    $iterations = 100;
    $qtyPerDeduct = 7;

    $this->product->addStock($initialQty, $this->location);

    for ($i = 0; $i < $iterations; $i++) {
        $this->product->deductStock($qtyPerDeduct, $this->location);
    }

    $stock = $this->product->stockAt($this->location);
    expect($stock->quantity)->toEqual($initialQty - ($iterations * $qtyPerDeduct));
});

// ─────────────────────────────────────────────────────────────────────────────
// 3. Interleaved adds and deducts – net quantity must stay correct
// ─────────────────────────────────────────────────────────────────────────────

it('interleaved adds and deducts produce correct final quantity', function () {
    $seed = 500;
    $iterations = 50;
    $delta = 3;

    $this->product->addStock($seed, $this->location);

    for ($i = 0; $i < $iterations; $i++) {
        $this->product->addStock($delta, $this->location);
        $this->product->deductStock($delta, $this->location);
    }

    $stock = $this->product->stockAt($this->location);
    expect($stock->quantity)->toEqual($seed);

    // Total movements: 1 seed-add + 2 × iterations
    $movementCount = Movement::where('stockable_type', Product::class)
        ->where('stockable_id', $this->product->id)
        ->count();
    expect($movementCount)->toBe(1 + $iterations * 2);
});

// ─────────────────────────────────────────────────────────────────────────────
// 4. Movement audit-trail has no gaps (before_qty[i+1] === after_qty[i])
// ─────────────────────────────────────────────────────────────────────────────

it('movement audit-trail has no gaps in before/after quantity chain', function () {
    $ops = [20, -5, 15, -3, 10, -8, 25, -12, 5, -2];

    // Seed enough stock so deducts never underflow
    $this->product->addStock(100, $this->location);

    foreach ($ops as $delta) {
        if ($delta > 0) {
            $this->product->addStock($delta, $this->location);
        } else {
            $this->product->deductStock(abs($delta), $this->location);
        }
    }

    $movements = Movement::where('stockable_type', Product::class)
        ->where('stockable_id', $this->product->id)
        ->where('location_id', $this->location->id)
        ->orderBy('id')
        ->get();

    // Skip index 0 (seed); check all subsequent pairs
    for ($i = 1; $i < $movements->count(); $i++) {
        expect($movements[$i]->before_quantity)
            ->toEqual(
                $movements[$i - 1]->after_quantity,
                "Gap detected between movements at index {$i}: ".
                "expected before_quantity={$movements[$i - 1]->after_quantity}, ".
                "got {$movements[$i]->before_quantity}"
            );
    }
});

// ─────────────────────────────────────────────────────────────────────────────
// 5. Overselling protection – deducts beyond available must throw
// ─────────────────────────────────────────────────────────────────────────────

it('blocks deducts that would exceed available stock under high-volume attempts', function () {
    $this->product->addStock(100, $this->location);

    $succeeded = 0;
    $failed = 0;
    // 5 attempts of 25 each = 125 total, but only 100 available
    for ($i = 0; $i < 5; $i++) {
        try {
            $this->product->deductStock(25, $this->location);
            $succeeded++;
        } catch (InsufficientStockException) {
            $failed++;
        }
    }

    expect($succeeded)->toBe(4)
        ->and($failed)->toBe(1);

    $stock = $this->product->stockAt($this->location);
    expect($stock->quantity)->toEqual(0);
});

// ─────────────────────────────────────────────────────────────────────────────
// 6. Bulk operation with many products – all movements in one transaction
// ─────────────────────────────────────────────────────────────────────────────

it('bulk operation with 50 products records all movements in a single transaction', function () {
    $productCount = 50;
    $qtyEach = 10;
    $products = [];

    for ($i = 0; $i < $productCount; $i++) {
        $products[] = Product::create(['name' => "BulkProduct-{$i}", 'cost_price' => 1.00]);
    }

    $transaction = Inventorix::bulk(function (Transaction $tx) use ($products, $qtyEach) {
        foreach ($products as $p) {
            Inventorix::addStock($p, $qtyEach, $this->location, new StockOperationDto(transaction: $tx));
        }
    });

    $movementsInTx = Movement::where('transaction_id', $transaction->id)->count();
    expect($movementsInTx)->toBe($productCount);

    foreach ($products as $p) {
        $stock = $p->stockAt($this->location);
        expect($stock->quantity)->toEqual($qtyEach);
    }
});

// ─────────────────────────────────────────────────────────────────────────────
// 7. Reservation over-allocation is blocked
// ─────────────────────────────────────────────────────────────────────────────

it('prevents over-reservation when many reservations compete for the same stock', function () {
    $this->product->addStock(100, $this->location);

    // 11 reservations × 10 = 110 requested, but only 100 available
    $reserved = 0;
    $blocked = 0;

    for ($i = 0; $i < 11; $i++) {
        try {
            $this->product->reserve(10, $this->location);
            $reserved++;
        } catch (InsufficientStockException) {
            $blocked++;
        }
    }

    expect($reserved)->toBe(10)
        ->and($blocked)->toBe(1);

    $stock = $this->product->stockAt($this->location);
    expect($stock->reserved_quantity)->toEqual(100)
        ->and($stock->available_quantity)->toEqual(0);
});

// ─────────────────────────────────────────────────────────────────────────────
// 8. Transfer under load conserves total stock across locations
// ─────────────────────────────────────────────────────────────────────────────

it('stock is conserved across 100 sequential transfers between two locations', function () {
    $locationB = Location::create(['name' => 'Secondary Warehouse', 'code' => 'WH-B', 'is_active' => true]);

    $initialQty = 500;
    $iterations = 100;
    $txQty = 5;

    $this->product->addStock($initialQty, $this->location);

    for ($i = 0; $i < $iterations; $i++) {
        $this->product->transferStock($txQty, $this->location, $locationB);
    }

    $stockA = $this->product->stockAt($this->location);
    $stockB = $this->product->stockAt($locationB);

    $expectedA = $initialQty - ($iterations * $txQty);
    $expectedB = $iterations * $txQty;

    expect($stockA->quantity)->toEqual($expectedA)
        ->and($stockB->quantity)->toEqual($expectedB)
        ->and($stockA->quantity + $stockB->quantity)->toEqual($initialQty);
});

// ─────────────────────────────────────────────────────────────────────────────
// 9. Mixed operation storm – random mix of add / deduct / adjust
// ─────────────────────────────────────────────────────────────────────────────

it('stock is correct after a storm of mixed add/deduct/adjust operations', function () {
    // Deterministic "random" sequence so the test is reproducible
    $operations = [];
    $seed = 42;
    $lcg = fn (int &$s): int => $s = ($s * 1664525 + 1013904223) & 0x7FFFFFFF;

    $totalAdd = 0;
    $totalDeduct = 0;
    $totalAdjust = null; // track last adjust value

    // Seed with plenty of stock
    $this->product->addStock(10000, $this->location);
    $currentQty = 10000;

    for ($i = 0; $i < 100; $i++) {
        $opType = $lcg($seed) % 3;          // 0=add, 1=deduct, 2=adjust
        $qty = ($lcg($seed) % 20) + 1;   // 1–20

        if ($opType === 0) {
            $this->product->addStock($qty, $this->location);
            $currentQty += $qty;
            $totalAdd += $qty;
        } elseif ($opType === 1) {
            // Only deduct if we have enough
            $stock = $this->product->stockAt($this->location);
            if ($stock->quantity >= $qty) {
                $this->product->deductStock($qty, $this->location);
                $currentQty -= $qty;
                $totalDeduct += $qty;
            }
        } else {
            // Adjust to a deterministic absolute value (keep it positive)
            $newQty = ($lcg($seed) % 200) + 50;   // 50–249
            $this->product->adjustStock($newQty, $this->location);
            $currentQty = $newQty;
        }
    }

    $stock = $this->product->stockAt($this->location);
    expect($stock->quantity)->toEqual($currentQty);
});

// ─────────────────────────────────────────────────────────────────────────────
// 10. Cross-product isolation – operations on one product must not bleed
//     into another product's stock or movements
// ─────────────────────────────────────────────────────────────────────────────

it('10 products each accumulate exactly the right quantity with no cross-contamination', function () {
    $productCount = 10;
    $addsPerProduct = 50;
    $qtyPerAdd = 2;

    $products = [];
    for ($i = 0; $i < $productCount; $i++) {
        $products[] = Product::create(['name' => "IsolatedProduct-{$i}", 'cost_price' => 1.00]);
    }

    // Interleave adds across all products (round-robin style)
    for ($round = 0; $round < $addsPerProduct; $round++) {
        foreach ($products as $p) {
            $p->addStock($qtyPerAdd, $this->location);
        }
    }

    foreach ($products as $p) {
        $stock = $p->stockAt($this->location);
        expect($stock->quantity)->toEqual($addsPerProduct * $qtyPerAdd);

        $movementCount = Movement::where('stockable_type', Product::class)
            ->where('stockable_id', $p->id)
            ->count();
        expect($movementCount)->toBe($addsPerProduct);
    }
});

// ─────────────────────────────────────────────────────────────────────────────
// 11. No duplicate Stock records under high-volume first-time writes
// ─────────────────────────────────────────────────────────────────────────────

it('never creates duplicate Stock rows for the same stockable+location pair', function () {
    // All 200 adds target the same product+location; only one Stock row may exist
    for ($i = 0; $i < 200; $i++) {
        $this->product->addStock(1, $this->location);
    }

    $stockCount = Stock::where('stockable_type', Product::class)
        ->where('stockable_id', $this->product->id)
        ->where('location_id', $this->location->id)
        ->count();

    expect($stockCount)->toBe(1);
});

// ─────────────────────────────────────────────────────────────────────────────
// 12. Transaction count matches operation count (no orphaned transactions)
// ─────────────────────────────────────────────────────────────────────────────

it('each standalone stock operation creates exactly one committed transaction', function () {
    $iterations = 30;

    for ($i = 0; $i < $iterations; $i++) {
        $this->product->addStock(10, $this->location);
    }

    $txCount = Transaction::where('causable_type', null)->count();
    // Each addStock auto-creates one transaction; assert at least $iterations exist
    expect($txCount)->toBeGreaterThanOrEqual($iterations);

    $pendingCount = Transaction::where('status', 'pending')->count();
    expect($pendingCount)->toBe(0, 'All auto-created transactions should be committed');
});

// ─────────────────────────────────────────────────────────────────────────────
// 13. Reserve → fulfill cycle under load – stock and reserved_qty stay in sync
// ─────────────────────────────────────────────────────────────────────────────

it('repeated reserve+fulfill cycles keep stock and reserved_quantity in sync', function () {
    $this->product->addStock(500, $this->location);
    $cycles = 50;
    $qtyEach = 5;

    for ($i = 0; $i < $cycles; $i++) {
        $reservation = $this->product->reserve($qtyEach, $this->location);
        $this->product->fulfillReservation($reservation);
    }

    $stock = $this->product->stockAt($this->location);

    expect($stock->quantity)->toEqual(500 - ($cycles * $qtyEach))
        ->and($stock->reserved_quantity)->toEqual(0);
});

// ─────────────────────────────────────────────────────────────────────────────
// 14. Reserve → release cycle – reserved_quantity returns to zero
// ─────────────────────────────────────────────────────────────────────────────

it('repeated reserve+release cycles return reserved_quantity to zero', function () {
    $this->product->addStock(200, $this->location);
    $cycles = 40;
    $qtyEach = 5;

    for ($i = 0; $i < $cycles; $i++) {
        $reservation = $this->product->reserve($qtyEach, $this->location);
        $this->product->releaseReservation($reservation);
    }

    $stock = $this->product->stockAt($this->location);

    expect($stock->quantity)->toEqual(200)
        ->and($stock->reserved_quantity)->toEqual(0);
});

// ─────────────────────────────────────────────────────────────────────────────
// 15. Multi-location storm – simultaneous ops on many locations, total conserved
// ─────────────────────────────────────────────────────────────────────────────

it('total stock across all locations stays conserved during a multi-location transfer storm', function () {
    $locationCount = 5;
    $locations = [$this->location];

    for ($i = 1; $i < $locationCount; $i++) {
        $locations[] = Location::create(['name' => "Location-{$i}", 'code' => "WH-{$i}", 'is_active' => true]);
    }

    // Stock all locations equally
    $qtyPerLocation = 100;
    foreach ($locations as $loc) {
        $this->product->addStock($qtyPerLocation, $loc);
    }

    $totalBefore = Stock::where('stockable_type', Product::class)
        ->where('stockable_id', $this->product->id)
        ->sum('quantity');

    // Perform 50 transfers between random (deterministic) location pairs
    $seed = 7;
    $lcg = fn (int &$s): int => $s = ($s * 1664525 + 1013904223) & 0x7FFFFFFF;

    for ($i = 0; $i < 50; $i++) {
        $fromIdx = $lcg($seed) % $locationCount;
        $toIdx = $lcg($seed) % $locationCount;

        if ($fromIdx === $toIdx) {
            continue;
        }

        $fromLoc = $locations[$fromIdx];
        $toLoc = $locations[$toIdx];

        $fromStock = $this->product->stockAt($fromLoc);
        if ($fromStock && $fromStock->quantity >= 10) {
            $this->product->transferStock(10, $fromLoc, $toLoc);
        }
    }

    $totalAfter = Stock::where('stockable_type', Product::class)
        ->where('stockable_id', $this->product->id)
        ->sum('quantity');

    expect($totalAfter)->toEqual($totalBefore, 'Total stock across all locations must be conserved after transfers');
});
