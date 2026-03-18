<?php

namespace Aldeebhasan\Inventorix\Commands;

use Aldeebhasan\Inventorix\Events\ReservationExpired;
use Aldeebhasan\Inventorix\Inventorix;
use Aldeebhasan\Inventorix\Models\Reservation;
use Illuminate\Console\Command;

class ExpireReservationsCommand extends Command
{
    public $signature = 'inventorix:expire-reservations';

    public $description = 'Release all reservations that have expired.';

    public function handle(): int
    {
        $count = 0;

        Reservation::expired()
            ->with(['location', 'stockable'])
            ->chunkById(100, function ($reservations) use (&$count) {
                foreach ($reservations as $reservation) {
                    try {
                        $stockable = $reservation->stockable;
                        $location = $reservation->location;

                        app(Inventorix::class)->releaseReservation($reservation);

                        event(new ReservationExpired($reservation, $stockable, $location));

                        $count++;
                    } catch (\Throwable $e) {
                        $this->error("Failed to expire reservation #{$reservation->id}: {$e->getMessage()}");
                    }
                }
            });

        $this->info("Expired {$count} reservation(s).");

        return self::SUCCESS;
    }
}
