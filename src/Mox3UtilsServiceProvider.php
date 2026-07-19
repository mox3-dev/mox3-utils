<?php

namespace Mox3\Utils;

use Illuminate\Support\ServiceProvider;
use Mox3\Utils\Console\Commands\SyncProductionDatabase;

class Mox3UtilsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                SyncProductionDatabase::class,
            ]);
        }
    }
}
