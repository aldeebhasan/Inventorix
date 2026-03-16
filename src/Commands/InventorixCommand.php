<?php

namespace Aldeebhasan\Inventorix\Commands;

use Illuminate\Console\Command;

class InventorixCommand extends Command
{
    public $signature = 'inventorix';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
