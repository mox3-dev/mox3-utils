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

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite', 'database' => ':memory:',
        ]);
        $app['config']->set('database.connections.production', [
            'driver' => 'mysql', 'host' => 'db.example.com', 'port' => 3306,
            'username' => 'u', 'password' => 'p', 'database' => 'prod_app',
        ]);
    }

    public function test_command_is_registered(): void
    {
        $this->assertArrayHasKey(
            'db:sync-production',
            $this->app[\Illuminate\Contracts\Console\Kernel::class]->all()
        );
    }

    public function test_dump_only_and_push_remote_together_fail(): void
    {
        $this->artisan('db:sync-production', ['--dump-only' => true, '--push-remote' => true])
            ->expectsOutputToContain('cannot be combined')
            ->assertExitCode(1);
    }

    public function test_push_remote_without_target_connection_fails(): void
    {
        $this->artisan('db:sync-production', ['--push-remote' => true, '--force' => true])
            ->expectsOutputToContain('requires --target-connection')
            ->assertExitCode(1);
    }

    public function test_push_remote_without_force_fails(): void
    {
        $this->artisan('db:sync-production', ['--push-remote' => true, '--target-connection' => 'production'])
            ->expectsOutputToContain('re-run with --force')
            ->assertExitCode(1);
    }

    public function test_non_mysql_source_connection_fails(): void
    {
        $this->artisan('db:sync-production', ['--source-connection' => 'testing', '--dump-only' => true])
            ->expectsOutputToContain('not a configured MySQL connection')
            ->assertExitCode(1);
    }
}
