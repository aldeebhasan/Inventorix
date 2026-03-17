<?php

namespace Aldeebhasan\Inventorix\Commands;

use Aldeebhasan\Inventorix\Models\Movement;
use Illuminate\Console\Command;

class PruneMovementsCommand extends Command
{
    public $signature = 'inventorix:prune-movements';

    public $description = 'Delete old movement records based on the configured prune age.';

    public function handle(): int
    {
        $days = config('inventorix.movement_prune_after_days');

        if ($days === null) {
            $this->info('Pruning disabled.');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays((int) $days);
        $count = Movement::where('created_at', '<', $cutoff)->delete();

        $this->info("Deleted {$count} movement record(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
