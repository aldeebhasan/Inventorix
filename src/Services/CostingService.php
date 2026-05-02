<?php

namespace Aldeebhasan\Inventorix\Services;

use Aldeebhasan\Inventorix\Enums\CostingStrategy;
use Aldeebhasan\Inventorix\Enums\MovementType;
use Aldeebhasan\Inventorix\Models\Movement;
use Aldeebhasan\Inventorix\Models\MovementSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CostingService
{
    /**
     * Link a deduction (or fulfillment) movement back to its source lots, then store the
     * derived cost_per_unit on the movement so retrieval is a plain column read.
     *
     * Dispatches to the correct path based on strategy — average has no ordering requirement
     * and no sequential loop, so it runs a separate, simpler query.
     */
    public function linkSources(Movement $deduction): void
    {
        $strategy = $this->resolveStrategy($deduction);

        if ($strategy === CostingStrategy::Average) {
            $this->linkAverageSources($deduction);
        } else {
            $this->linkSequentialSources($deduction, $strategy);
        }
    }

    private function resolveStrategy(Movement $deduction): CostingStrategy
    {
        $stockable = $deduction->stockable;

        if ($stockable !== null && method_exists($stockable, 'inventorixCostingStrategy')) {
            return $stockable->inventorixCostingStrategy();
        }

        return CostingStrategy::fromConfig();
    }

    /**
     * Recompute the cost_per_unit for a deduction from its current movement_sources rows
     * and persist the result. Call this after correcting a source lot's cost_per_unit.
     *
     * Returns the new cost, or null when no sources exist.
     */
    public function recomputeAndStore(Movement $movement): ?float
    {
        /** @var Collection<MovementSource> $sources */
        $sources = MovementSource::where('deduction_movement_id', $movement->id)
            ->with('sourceMovement')
            ->get();

        if ($sources->isEmpty()) {
            return null;
        }

        $totalQty = $sources->sum(fn ($s) => (float) $s->quantity);

        if ($totalQty <= 0.0) {
            return null;
        }

        $totalCost = $sources->sum(fn ($s) => (float) $s->quantity * (float) $s->sourceMovement->cost_per_unit);
        $cost = $totalCost / $totalQty;

        $movement->update(['cost_per_unit' => $cost]);

        return $cost;
    }

    /**
     * FIFO / LIFO: fetch only lots that still have remaining stock (DB-filtered), ordered by
     * strategy direction, then consume them sequentially until the deduction is satisfied.
     * FEFO: consume the nearest-expiry lot first. Lots with no expires_at are treated
     *  as last resort (null-last ordering). Falls back to created_at / id for ties.
     */
    private function linkSequentialSources(Movement $deduction, CostingStrategy $strategy): void
    {
        $inboundQuery = $this->inboundQuery($deduction)
            ->whereRaw('quantity > consumed_quantity');

        if ($strategy === CostingStrategy::Fefo) {
            $inboundQuery
                ->orderByRaw('CASE WHEN expires_at IS NULL THEN 1 ELSE 0 END ASC')
                ->orderBy('expires_at')
                ->orderBy('created_at')
                ->orderBy('id');
        } else {
            $order = $strategy === CostingStrategy::Lifo ? 'desc' : 'asc';
            $inboundQuery
                ->orderBy('created_at', $order)
                ->orderBy('id', $order);
        }

        /** @var Collection<Movement> $inbound */
        $inbound = $inboundQuery->get();

        if ($inbound->isEmpty()) {
            return;
        }

        [$sources, $costMap] = $this->buildSequentialSources($deduction, $inbound);

        $this->persistSources($deduction, $sources, $costMap);
    }

    /**
     * AVERAGE: compute the weighted average cost across all lots with remaining stock,
     * then consume lots sequentially (oldest-first) for the audit trail.
     *
     * Proportional spreading is deliberately avoided: it creates fractional
     * consumed_quantity values that break integer-quantity businesses and accumulate
     * rounding drift over time. Sequential consumption is fraction-free — each lot
     * gives up whole (or exact float) units — while the cost_per_unit on the
     * deduction still reflects the true weighted average across all available stock.
     */
    private function linkAverageSources(Movement $deduction): void
    {
        /** @var Collection<Movement> $inbound */
        $inbound = $this->inboundQuery($deduction)
            ->whereRaw('quantity > consumed_quantity')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        if ($inbound->isEmpty()) {
            return;
        }

        // Weighted average cost from ALL available lots — not just the ones consumed.
        $totalAvailableQty = 0.0;
        $totalAvailableCost = 0.0;
        foreach ($inbound as $m) {
            $lotRemaining = (float) $m->quantity - (float) $m->consumed_quantity;
            $totalAvailableQty += $lotRemaining;
            $totalAvailableCost += $lotRemaining * (float) $m->cost_per_unit;
        }

        if ($totalAvailableQty <= 0.0) {
            return;
        }

        $avgCostPerUnit = $totalAvailableCost / $totalAvailableQty;

        // Cost map uses the same average for every lot so persistSources derives
        // the correct cost_per_unit on the deduction without extra logic.
        [$sources, $costMap] = $this->buildSequentialSources($deduction, $inbound, $avgCostPerUnit);

        $this->persistSources($deduction, $sources, $costMap);
    }

    /**
     * Consume $inbound lots sequentially until the deduction quantity is satisfied.
     * Returns [$sources, $costMap] ready for persistSources().
     *
     * When $overrideCostPerUnit is provided (average strategy) every entry in the
     * cost map gets that value instead of the lot's own cost_per_unit.
     *
     * @param  Collection<Movement>  $inbound
     * @return array{array<int, array{deduction_movement_id: int, source_movement_id: int, quantity: float}>, array<int, float>}
     */
    private function buildSequentialSources(
        Movement $deduction,
        Collection $inbound,
        ?float $overrideCostPerUnit = null,
    ): array {
        $remaining = (float) $deduction->quantity;
        $sources = [];
        $costMap = [];

        foreach ($inbound as $m) {
            if ($remaining <= 0.0) {
                break;
            }

            $lotRemaining = (float) $m->quantity - (float) $m->consumed_quantity;
            $take = min($lotRemaining, $remaining);

            $sources[] = [
                'deduction_movement_id' => $deduction->id,
                'source_movement_id' => $m->id,
                'quantity' => $take,
            ];
            $costMap[$m->id] = $overrideCostPerUnit ?? (float) $m->cost_per_unit;
            $remaining -= $take;
        }

        return [$sources, $costMap];
    }

    /**
     * Base query for inbound costed movements scoped to the same stockable/location,
     * created before this deduction.
     */
    private function inboundQuery(Movement $deduction): Builder
    {
        return Movement::where('stockable_type', $deduction->stockable_type)
            ->where('stockable_id', $deduction->stockable_id)
            ->where('location_id', $deduction->location_id)
            ->whereNotNull('cost_per_unit')
            ->where('type', MovementType::Add->value)
            ->where('id', '<', $deduction->id);
    }

    /**
     * Persist movement_sources rows, increment consumed_quantity on each source lot,
     * and store the derived cost_per_unit on the deduction — all from in-memory data.
     */
    private function persistSources(Movement $deduction, array $sources, array $costMap): void
    {
        if (empty($sources)) {
            return;
        }

        // 1. Batch-insert all MovementSource rows in one query
        $now = now()->toDateTimeString();
        $rows = array_map(fn ($s) => array_merge($s, ['created_at' => $now, 'updated_at' => $now]), $sources);
        MovementSource::insert($rows);

        // 2. Batch-update consumed_quantity on all source movements in one query
        $this->batchIncrementConsumed($sources);

        // 3. Derive and store cost_per_unit on the deduction
        $totalQty = 0.0;
        $totalCost = 0.0;
        foreach ($sources as $source) {
            $qty = (float) $source['quantity'];
            $totalQty += $qty;
            $totalCost += $qty * ($costMap[$source['source_movement_id']] ?? 0.0);
        }

        if ($totalQty > 0.0) {
            $deduction->update(['cost_per_unit' => round($totalCost / $totalQty, 4)]);
        }
    }

    /**
     * Batch-update consumed_quantity on multiple source movements in a single SQL statement.
     */
    private function batchIncrementConsumed(array $sources): void
    {
        if (empty($sources)) {
            return;
        }

        $table = (new Movement)->getTable();
        $cases = '';
        $ids = [];
        $bindings = [];

        foreach ($sources as $source) {
            $cases .= ' WHEN id = ? THEN ?';
            $bindings[] = $source['source_movement_id'];
            $bindings[] = (float) $source['quantity'];
            $ids[] = $source['source_movement_id'];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        DB::statement(
            "UPDATE {$table} SET consumed_quantity = consumed_quantity + CASE{$cases} ELSE 0 END WHERE id IN ({$placeholders})",
            array_merge($bindings, $ids),
        );
    }
}
