<?php

namespace Aldeebhasan\Inventorix\Services;

use Aldeebhasan\Inventorix\Enums\SerialStatus;
use Aldeebhasan\Inventorix\Exceptions\InvalidSerialOperationException;
use Aldeebhasan\Inventorix\Models\Location;
use Aldeebhasan\Inventorix\Models\Movement;
use Aldeebhasan\Inventorix\Models\Reservation;
use Aldeebhasan\Inventorix\Models\Serial;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SerialService
{
    /**
     * Create Serial records for an inbound movement.
     *
     * When serial tracking is disabled this is a no-op.
     * When $serials is empty, one ULID is auto-generated per unit.
     * When $serials is provided its count must equal $quantity exactly.
     */
    public function attach(Movement $movement, Model $stockable, Location $location, int|float $quantity, array $serials = []): void
    {
        if (! config('inventorix.serial_tracking.enabled', false)) {
            return;
        }

        $qty = (int) $quantity;

        if ($serials === []) {
            $serials = $this->generateSerials($qty);
        } elseif (count($serials) !== $qty) {
            throw new InvalidSerialOperationException(
                'Serial count ('.count($serials).') must match the operation quantity ('.$qty.').'
            );
        }

        $now = now();
        $rows = array_map(fn (string $sn) => [
            'stockable_type' => get_class($stockable),
            'stockable_id' => $stockable->getKey(),
            'location_id' => $location->id,
            'serial_number' => $sn,
            'status' => SerialStatus::Available->value,
            'movement_id' => $movement->id,
            'meta' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $serials);

        Serial::insert($rows);
    }

    /**
     * Mark Serial records as Sold for an outbound movement.
     *
     * When serial tracking is disabled this is a no-op.
     * When $serials is empty, the oldest $quantity available serials at the
     * location are auto-selected (FIFO by created_at / id).
     * When $serials is provided its count must equal $quantity and every serial
     * must be Available or Reserved at the given location.
     */
    public function detach(Movement $movement, Model $stockable, Location $location, int|float $quantity, array $serials = []): void
    {
        if (! config('inventorix.serial_tracking.enabled', false)) {
            return;
        }

        $qty = (int) $quantity;

        if ($serials === []) {
            $serials = Serial::where('stockable_type', get_class($stockable))
                ->where('stockable_id', $stockable->getKey())
                ->where('location_id', $location->id)
                ->where('status', SerialStatus::Available->value)
                ->orderBy('created_at')
                ->orderBy('id')
                ->limit($qty)
                ->pluck('serial_number')
                ->all();

            if (count($serials) < $qty) {
                throw new InvalidSerialOperationException(
                    'Not enough available serial numbers at this location. Available: '.count($serials).', Requested: '.$qty.'.'
                );
            }

            $allowedStatuses = [SerialStatus::Available->value];
        } else {
            if (count($serials) !== $qty) {
                throw new InvalidSerialOperationException(
                    'Serial count ('.count($serials).') must match the operation quantity ('.$qty.').'
                );
            }

            // Explicit serials may include Reserved ones (e.g. from a fulfillment path)
            $allowedStatuses = [SerialStatus::Available->value, SerialStatus::Reserved->value];
        }

        $updated = Serial::where('stockable_type', get_class($stockable))
            ->where('stockable_id', $stockable->getKey())
            ->where('location_id', $location->id)
            ->whereIn('status', $allowedStatuses)
            ->whereIn('serial_number', $serials)
            ->update([
                'status' => SerialStatus::Sold->value,
                'movement_id' => $movement->id,
                'reservation_id' => null,
                'updated_at' => now(),
            ]);

        if ($updated !== $qty) {
            throw new InvalidSerialOperationException(
                'One or more serial numbers are not available at this location.'
            );
        }
    }

    /**
     * Mark $quantity Available serials as Reserved and link them to the reservation.
     *
     * When serial tracking is disabled this is a no-op.
     * When $serials is empty, the oldest available serials are auto-selected (FIFO).
     * When $serials is provided its count must equal $quantity exactly.
     */
    public function reserveSerials(Reservation $reservation, Model $stockable, Location $location, int|float $quantity, array $serials = []): void
    {
        if (! config('inventorix.serial_tracking.enabled', false)) {
            return;
        }

        $qty = (int) $quantity;

        if ($serials === []) {
            $serials = Serial::where('stockable_type', get_class($stockable))
                ->where('stockable_id', $stockable->getKey())
                ->where('location_id', $location->id)
                ->where('status', SerialStatus::Available->value)
                ->orderBy('created_at')
                ->orderBy('id')
                ->limit($qty)
                ->pluck('serial_number')
                ->all();

            if (count($serials) < $qty) {
                throw new InvalidSerialOperationException(
                    'Not enough available serial numbers to reserve. Available: '.count($serials).', Requested: '.$qty.'.'
                );
            }
        } elseif (count($serials) !== $qty) {
            throw new InvalidSerialOperationException(
                'Serial count ('.count($serials).') must match the reservation quantity ('.$qty.').'
            );
        }

        $updated = Serial::where('stockable_type', get_class($stockable))
            ->where('stockable_id', $stockable->getKey())
            ->where('location_id', $location->id)
            ->where('status', SerialStatus::Available->value)
            ->whereIn('serial_number', $serials)
            ->update([
                'status' => SerialStatus::Reserved->value,
                'reservation_id' => $reservation->id,
                'updated_at' => now(),
            ]);

        if ($updated !== $qty) {
            throw new InvalidSerialOperationException(
                'One or more serial numbers are not available for reservation.'
            );
        }
    }

    /**
     * Release all Reserved serials tied to a reservation back to Available.
     *
     * When serial tracking is disabled this is a no-op.
     */
    public function unreserveSerials(Reservation $reservation): void
    {
        if (! config('inventorix.serial_tracking.enabled', false)) {
            return;
        }

        Serial::where('reservation_id', $reservation->id)
            ->where('status', SerialStatus::Reserved->value)
            ->update([
                'status' => SerialStatus::Available->value,
                'reservation_id' => null,
                'updated_at' => now(),
            ]);
    }

    /**
     * Return the serial numbers currently Reserved for a given reservation.
     *
     * Returns an empty array when serial tracking is disabled.
     */
    public function getReservedSerials(Reservation $reservation): array
    {
        if (! config('inventorix.serial_tracking.enabled', false)) {
            return [];
        }

        return Serial::where('reservation_id', $reservation->id)
            ->where('status', SerialStatus::Reserved->value)
            ->pluck('serial_number')
            ->all();
    }

    private function generateSerials(int $count): array
    {
        return array_map(fn () => (string) Str::ulid(), range(1, $count));
    }
}
