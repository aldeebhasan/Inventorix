<?php

namespace Aldeebhasan\Inventorix;

use Illuminate\Support\ServiceProvider;
use Aldeebhasan\Inventorix\Commands\InventorixCommand;

class InventorixServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Views
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'inventorix');

        $this->publishes([__DIR__ . '/../config/inventorix.php' => config_path('inventorix.php')], 'inventorix-config');

        $this->publishes([
            __DIR__ . '/../database/migrations/create_inventorix_table.php.stub' => database_path(
                sprintf('migrations/%s_create_inventorix_table.php', date('Y_m_d_His'))
            ),
        ], 'inventorix-migrations');
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/inventorix.php', 'inventorix');
    }
}
