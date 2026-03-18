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
        $count = 0;

        Movement::where('created_at', '<', $cutoff)
            ->chunkById(500, function ($movements) use (&$count) {
                $ids = $movements->pluck('id');
                Movement::whereIn('id', $ids)->delete();
                $count += $ids->count();
            });

        $this->info("Deleted {$count} movement record(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
