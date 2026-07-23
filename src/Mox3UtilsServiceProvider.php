<?php

namespace Mox3\Utils;

use Illuminate\Support\ServiceProvider;
use Mox3\Utils\Console\Commands\SyncProductionDatabase;

class Mox3UtilsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/mox3-utils.php', 'mox3-utils');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                SyncProductionDatabase::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/mox3-utils.php' => config_path('mox3-utils.php'),
            ], 'mox3-utils-config');
        }
    }
}
