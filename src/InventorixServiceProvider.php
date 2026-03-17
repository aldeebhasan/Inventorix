<?php

namespace Aldeebhasan\Inventorix;

use Aldeebhasan\Inventorix\Commands\ExpireReservationsCommand;
use Aldeebhasan\Inventorix\Commands\PruneMovementsCommand;
use Aldeebhasan\Inventorix\Commands\StockReportCommand;
use Illuminate\Support\ServiceProvider;

class InventorixServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'inventorix');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->publishes([
            __DIR__.'/../config/inventorix.php' => config_path('inventorix.php'),
        ], 'inventorix-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'inventorix-migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                ExpireReservationsCommand::class,
                PruneMovementsCommand::class,
                StockReportCommand::class,
            ]);
        }
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/inventorix.php', 'inventorix');

        $this->app->singleton(Inventorix::class);
    }
}
