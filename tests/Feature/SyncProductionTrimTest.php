<?php

namespace Mox3\Utils\Tests\Feature;

use Mox3\Utils\Mox3UtilsServiceProvider;
use Orchestra\Testbench\TestCase;

class SyncProductionTrimTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [Mox3UtilsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // A MySQL default so mode-2 target resolution succeeds and we reach the
        // summary + confirmation without needing a live server (we decline it).
        $app['config']->set('database.default', 'local');
        $app['config']->set('database.connections.local', [
            'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 3306,
            'username' => 'u', 'password' => 'p', 'database' => 'local_app',
        ]);
        $app['config']->set('database.connections.production', [
            'driver' => 'mysql', 'host' => 'db.example.com', 'port' => 3306,
            'username' => 'u', 'password' => 'p', 'database' => 'prod_app',
        ]);
    }

    private const CONFIRM = "This ERASES the target database(s) 'local_app' and replaces them. Proceed?";

    public function test_summary_reports_full_dump_by_default(): void
    {
        $this->artisan('db:sync-production')
            ->expectsConfirmation(self::CONFIRM, 'no')
            ->expectsOutputToContain('full')
            ->assertExitCode(0);
    }

    public function test_summary_reports_trim_window_when_enabled(): void
    {
        $this->artisan('db:sync-production', ['--trim-logs' => true, '--log-days' => 15])
            ->expectsConfirmation(self::CONFIRM, 'no')
            ->expectsOutputToContain('trimmed to last 15 days')
            ->assertExitCode(0);
    }

    public function test_log_days_below_one_is_rejected(): void
    {
        $this->artisan('db:sync-production', ['--trim-logs' => true, '--log-days' => 0])
            ->expectsOutputToContain('--log-days must be at least 1')
            ->assertExitCode(1);
    }

    public function test_trim_table_option_is_repeatable_and_parses(): void
    {
        $this->artisan('db:sync-production', [
            '--trim-logs' => true,
            '--trim-table' => ['logs:timestamp', 'log:createdOn'],
        ])
            ->expectsConfirmation(self::CONFIRM, 'no')
            ->assertExitCode(0);
    }
}
