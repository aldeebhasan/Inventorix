<?php

namespace Aldeebhasan\Inventorix\Commands;

use Aldeebhasan\Inventorix\Models\Stock;
use Illuminate\Console\Command;

class StockReportCommand extends Command
{
    public $signature = 'inventorix:stock-report {--location= : Filter by location ID} {--type= : Filter by stockable type}';

    public $description = 'Output a stock summary table to the console.';

    public function handle(): int
    {
        $query = Stock::with('location');

        if ($locationId = $this->option('location')) {
            $query->where('location_id', (int) $locationId);
        }

        if ($type = $this->option('type')) {
            $query->where('stockable_type', $type);
        }

        $stocks = $query->get();

        if ($stocks->isEmpty()) {
            $this->info('No stock records found.');

            return self::SUCCESS;
        }

        $rows = $stocks->map(function (Stock $stock) {
            return [
                $stock->stockable_type,
                $stock->stockable_id,
                $stock->location?->name ?? $stock->location_id,
                $stock->quantity,
                $stock->reserved_quantity,
                max(0, $stock->quantity - $stock->reserved_quantity),
            ];
        })->toArray();

        $this->table(
            ['Stockable Type', 'Stockable ID', 'Location', 'Quantity', 'Reserved', 'Available'],
            $rows
        );

        return self::SUCCESS;
    }
}
