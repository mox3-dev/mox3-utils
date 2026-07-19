<?php

namespace Mox3\Utils\Tests\Feature;

use Mox3\Utils\Mox3UtilsServiceProvider;
use Orchestra\Testbench\TestCase;

class SyncProductionDatabaseGuardTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [Mox3UtilsServiceProvider::class];
    }

    public function test_command_is_registered(): void
    {
        $this->assertArrayHasKey(
            'db:sync-production',
            $this->app[\Illuminate\Contracts\Console\Kernel::class]->all()
        );
    }
}
