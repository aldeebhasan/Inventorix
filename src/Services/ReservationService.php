<?php

namespace Aldeebhasan\Inventorix\Services;

use Aldeebhasan\Inventorix\Contracts\ReservationServiceInterface;
use Aldeebhasan\Inventorix\Contracts\StockServiceInterface;
use Aldeebhasan\Inventorix\DTOs\StockOperationDto;
use Aldeebhasan\Inventorix\Enums\ReservationStatus;
use Aldeebhasan\Inventorix\Enums\TransactionStatus;
use Aldeebhasan\Inventorix\Enums\TransactionType;
use Aldeebhasan\Inventorix\Events\ReservationFulfilled;
use Aldeebhasan\Inventorix\Events\ReservationReleased;
use Aldeebhasan\Inventorix\Events\StockReserved;
use Aldeebhasan\Inventorix\Exceptions\InsufficientStockException;
use Aldeebhasan\Inventorix\Exceptions\InvalidQuantityException;
use Aldeebhasan\Inventorix\Exceptions\ReservationAlreadyFulfilledException;
use Aldeebhasan\Inventorix\Exceptions\ReservationNotFoundException;
use Aldeebhasan\Inventorix\Models\Location;
use Aldeebhasan\Inventorix\Models\Reservation;
use Aldeebhasan\Inventorix\Models\Stock;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class ReservationService extends BaseService implements ReservationServiceInterface
{
    public function __construct(
        private readonly Dispatcher $events,
        private readonly StockServiceInterface $stocks,
        private readonly SerialService $serials,
    ) {}

    public function reserve(Model $stockable, int|float $quantity, Location $location, StockOperationDto $options = new StockOperationDto): Reservation
    {
        if ($quantity <= 0) {
            throw new InvalidQuantityException;
        }

        return DB::transaction(function () use ($stockable, $quantity, $location, $options) {
            $stock = $this->findOrCreateStock($stockable, $location);

            if ($stock->available_quantity < $quantity) {
                throw new InsufficientStockException(
                    "Insufficient available stock. Available: {$stock->available_quantity}, Requested: {$quantity}."
                );
            }

            $stock->increment('reserved_quantity', $quantity);

            $expiresAt = $options->expiresAt;
            if ($expiresAt === null && config('inventorix.reservation_ttl_minutes') !== null) {
                $expiresAt = now()->addMinutes(config('inventorix.reservation_ttl_minutes'));
            }

            $reservation = Reservation::create([
                'stockable_type' => get_class($stockable),
                'stockable_id' => $stockable->getKey(),
                'location_id' => $location->id,
                'quantity' => $quantity,
                'status' => ReservationStatus::Pending,
                'reference_type' => $options->reference ? get_class($options->reference) : null,
                'reference_id' => $options->reference?->getKey(),
                'note' => $options->note,
                'created_by' => $options->createdBy,
                'expires_at' => $expiresAt,
            ]);

            $this->serials->reserveSerials($reservation, $stockable, $location, $quantity, $options->serials);

            if ($this->shouldDispatch('StockReserved')) {
                $this->events->dispatch(new StockReserved($stockable, $reservation, $location));
            }

            return $reservation;
        });
    }

    public function release(Reservation|int $reservation): bool
    {
        if (is_int($reservation)) {
            $reservation = Reservation::find($reservation);
            if (! $reservation) {
                throw new ReservationNotFoundException;
            }
        }

        if ($reservation->status !== ReservationStatus::Pending) {
            throw new ReservationAlreadyFulfilledException;
        }

        return DB::transaction(function () use ($reservation) {
            $location = $reservation->location;
            $stockable = $reservation->stockable;

            $stock = $this->findOrCreateStock($stockable, $location);

            $stock->decrement('reserved_quantity', $reservation->quantity);

            $this->serials->unreserveSerials($reservation);

            $reservation->update(['status' => ReservationStatus::Released]);

            if ($this->shouldDispatch('ReservationReleased')) {
                $this->events->dispatch(new ReservationReleased($reservation, $stockable, $location));
            }

            return true;
        });
    }

    public function fulfill(Reservation|int $reservation): Stock
    {
        if (is_int($reservation)) {
            $reservation = Reservation::find($reservation);
            if (! $reservation) {
                throw new ReservationNotFoundException;
            }
        }

        if ($reservation->status !== ReservationStatus::Pending) {
            throw new ReservationAlreadyFulfilledException;
        }

        return DB::transaction(function () use ($reservation) {
            $location = $reservation->location;
            $stockable = $reservation->stockable;
            $causable = $reservation->reference;

            [$transaction, $autoCreated] = $this->resolveOrCreateTransaction(
                new StockOperationDto(
                    causable: $causable,
                    note: $reservation->note,
                    createdBy: $reservation->created_by,
                ),
                TransactionType::Sale
            );

            $reservedSerials = $this->serials->getReservedSerials($reservation);

            $deductDto = (new StockOperationDto(
                transaction: $transaction,
                causable: $causable,
                note: $reservation->note,
                createdBy: $reservation->created_by,
                serials: $reservedSerials,
            ))->fromReservation();

            $stock = $this->stocks->deduct($stockable, $reservation->quantity, $location, $deductDto);

            $stock->decrement('reserved_quantity', $reservation->quantity);
            $stock->refresh();

            $reservation->update(['status' => ReservationStatus::Fulfilled]);

            if ($autoCreated) {
                $transaction->update(['status' => TransactionStatus::Committed]);
            }

            if ($this->shouldDispatch('ReservationFulfilled')) {
                $this->events->dispatch(new ReservationFulfilled($reservation, $stock, $stockable, $location));
            }

            return $stock;
        });
    }
}
