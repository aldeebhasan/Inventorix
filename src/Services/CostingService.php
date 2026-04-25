<?php

namespace Aldeebhasan\Inventorix\Services;

use Aldeebhasan\Inventorix\Enums\CostingStrategy;
use Aldeebhasan\Inventorix\Enums\MovementType;
use Aldeebhasan\Inventorix\Models\Movement;
use Aldeebhasan\Inventorix\Models\MovementSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

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
        $strategy = CostingStrategy::fromConfig();

        if ($strategy === CostingStrategy::Average) {
            $this->linkAverageSources($deduction);
        } else {
            $this->linkSequentialSources($deduction, $strategy);
        }
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
            $costMap[$m->id] = (float) $m->cost_per_unit;
            $remaining -= $take;
        }

        $this->persistSources($deduction, $sources, $costMap);
    }

    /**
     * AVERAGE: fetch only lots with remaining stock (DB-filtered, no ordering needed),
     * then distribute the deduction quantity proportionally across them.
     */
    private function linkAverageSources(Movement $deduction): void
    {
        /** @var Collection<Movement> $inbound */
        $inbound = $this->inboundQuery($deduction)
            ->whereRaw('quantity > consumed_quantity')
            ->get();

        if ($inbound->isEmpty()) {
            return;
        }

        $totalRemaining = $inbound->sum(fn ($m) => (float) $m->quantity - (float) $m->consumed_quantity);

        if ($totalRemaining <= 0.0) {
            return;
        }

        $deductionQty = (float) $deduction->quantity;
        $sources = [];
        $costMap = [];

        foreach ($inbound as $m) {
            $lotRemaining = (float) $m->quantity - (float) $m->consumed_quantity;
            $qty = round(($lotRemaining / $totalRemaining) * $deductionQty, 4);

            if ($qty > 0.0) {
                $sources[] = [
                    'deduction_movement_id' => $deduction->id,
                    'source_movement_id' => $m->id,
                    'quantity' => $qty,
                ];
                $costMap[$m->id] = (float) $m->cost_per_unit;
            }
        }

        $this->persistSources($deduction, $sources, $costMap);
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

        $totalQty = 0.0;
        $totalCost = 0.0;

        foreach ($sources as $source) {
            MovementSource::create($source);
            Movement::where('id', $source['source_movement_id'])
                ->increment('consumed_quantity', $source['quantity']);

            $qty = (float) $source['quantity'];
            $totalQty += $qty;
            $totalCost += $qty * ($costMap[$source['source_movement_id']] ?? 0.0);
        }

        if ($totalQty > 0.0) {
            $deduction->update(['cost_per_unit' => $totalCost / $totalQty]);
        }
    }
}
