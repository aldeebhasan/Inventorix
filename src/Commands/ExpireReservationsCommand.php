<?php

namespace Aldeebhasan\Inventorix\Commands;

use Aldeebhasan\Inventorix\Enums\ReservationStatus;
use Aldeebhasan\Inventorix\Events\ReservationExpired;
use Aldeebhasan\Inventorix\Inventorix;
use Aldeebhasan\Inventorix\Models\Reservation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Event;

class ExpireReservationsCommand extends Command
{
    public $signature = 'inventorix:expire-reservations';

    public $description = 'Release all reservations that have expired.';

    public function handle(): int
    {
        $expired = Reservation::expired()->with(['location', 'stockable'])->get();
        $count = 0;

        foreach ($expired as $reservation) {
            try {
                $stockable = $reservation->stockable;
                $location = $reservation->location;

                // Manually perform the release logic, but fire ReservationExpired instead
                app(Inventorix::class)->releaseReservation($reservation);

                // Override event: fire ReservationExpired instead of ReservationReleased
                event(new ReservationExpired($reservation, $stockable, $location));

                $count++;
            } catch (\Throwable $e) {
                $this->error("Failed to expire reservation #{$reservation->id}: {$e->getMessage()}");
            }
        }

        $this->info("Expired {$count} reservation(s).");

        return self::SUCCESS;
    }
}
