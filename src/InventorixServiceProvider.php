<?php

namespace Aldeebhasan\Inventorix;

use Aldeebhasan\Inventorix\Commands\ExpireReservationsCommand;
use Aldeebhasan\Inventorix\Commands\PruneMovementsCommand;
use Aldeebhasan\Inventorix\Commands\StockReportCommand;
use Aldeebhasan\Inventorix\Contracts\ReservationServiceInterface;
use Aldeebhasan\Inventorix\Contracts\RollbackServiceInterface;
use Aldeebhasan\Inventorix\Contracts\StockQueryInterface;
use Aldeebhasan\Inventorix\Contracts\StockServiceInterface;
use Aldeebhasan\Inventorix\Contracts\ThresholdServiceInterface;
use Aldeebhasan\Inventorix\Contracts\TransferServiceInterface;
use Aldeebhasan\Inventorix\Contracts\ValuationServiceInterface;
use Aldeebhasan\Inventorix\Queries\StockQueries;
use Aldeebhasan\Inventorix\Queries\StockVelocityQuery;
use Aldeebhasan\Inventorix\Services\CostingService;
use Aldeebhasan\Inventorix\Services\ReservationService;
use Aldeebhasan\Inventorix\Services\RollbackService;
use Aldeebhasan\Inventorix\Services\SerialService;
use Aldeebhasan\Inventorix\Services\StockService;
use Aldeebhasan\Inventorix\Services\ThresholdService;
use Aldeebhasan\Inventorix\Services\TransferService;
use Aldeebhasan\Inventorix\Services\ValuationService;
use Aldeebhasan\Inventorix\Support\ThresholdCache;
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

        $this->app->singleton(CostingService::class);
        $this->app->singleton(SerialService::class);
        $this->app->singleton(ThresholdCache::class);
        $this->app->bind(ThresholdServiceInterface::class, ThresholdService::class);
        $this->app->bind(StockServiceInterface::class, StockService::class);
        $this->app->bind(TransferServiceInterface::class, TransferService::class);
        $this->app->bind(ReservationServiceInterface::class, ReservationService::class);
        $this->app->bind(StockQueryInterface::class, StockQueries::class);
        $this->app->singleton(ValuationServiceInterface::class, ValuationService::class);
        $this->app->bind(RollbackServiceInterface::class, RollbackService::class);
        $this->app->singleton(StockVelocityQuery::class);

        $this->app->singleton(Inventorix::class, function ($app) {
            return new Inventorix(
                $app->make(StockServiceInterface::class),
                $app->make(TransferServiceInterface::class),
                $app->make(ReservationServiceInterface::class),
                $app->make(ValuationServiceInterface::class),
                $app->make(ThresholdServiceInterface::class),
                $app->make(StockQueryInterface::class),
                $app->make(RollbackServiceInterface::class),
                $app->make(StockVelocityQuery::class),
            );
        });
    }
}
