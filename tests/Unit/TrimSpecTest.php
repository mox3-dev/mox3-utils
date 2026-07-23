<?php

namespace Mox3\Utils\Tests\Unit;

use Mox3\Utils\DbSync\TrimSpec;
use PHPUnit\Framework\TestCase;

class TrimSpecTest extends TestCase
{
    private function config(): array
    {
        return [
            'qualified' => [
                'mortrac.logs_api_v3' => 'timestamp',
                'mortrac.user_time' => 'login',
            ],
            'by_table' => [
                'log' => 'createdOn',
                'notifications' => 'created_at',
            ],
            'exclude_schemas' => ['pti_dw'],
        ];
    }

    public function test_qualified_config_entry_matches_only_its_schema(): void
    {
        $spec = TrimSpec::fromConfigAndCli($this->config(), []);

        $this->assertSame(
            ['column' => 'timestamp', 'explicit' => true],
            $spec->columnFor('mortrac', 'logs_api_v3')
        );
        // Same table name in a different schema is NOT matched by a qualified entry.
        $this->assertNull($spec->columnFor('trxio', 'logs_api_v3'));
    }

    public function test_by_table_matches_any_schema_and_is_not_explicit(): void
    {
        $spec = TrimSpec::fromConfigAndCli($this->config(), []);

        $this->assertSame(
            ['column' => 'createdOn', 'explicit' => false],
            $spec->columnFor('tenant_1234', 'log')
        );
        $this->assertSame(
            ['column' => 'createdOn', 'explicit' => false],
            $spec->columnFor('some_other_tenant', 'log')
        );
    }

    public function test_excluded_schema_suppresses_by_table_match(): void
    {
        $spec = TrimSpec::fromConfigAndCli($this->config(), []);
        $this->assertNull($spec->columnFor('pti_dw', 'notifications'));
    }

    public function test_qualified_beats_by_table_for_the_same_table(): void
    {
        $config = [
            'qualified' => ['mortrac.log' => 'ts_col'],
            'by_table' => ['log' => 'createdOn'],
        ];
        $spec = TrimSpec::fromConfigAndCli($config, []);

        $this->assertSame(['column' => 'ts_col', 'explicit' => true], $spec->columnFor('mortrac', 'log'));
        // In another schema only the by_table entry applies.
        $this->assertSame(['column' => 'createdOn', 'explicit' => false], $spec->columnFor('trxio', 'log'));
    }

    public function test_cli_qualified_override_wins_over_config_and_is_explicit(): void
    {
        $spec = TrimSpec::fromConfigAndCli($this->config(), ['mortrac.logs_api_v3:created_on']);
        $this->assertSame(['column' => 'created_on', 'explicit' => true], $spec->columnFor('mortrac', 'logs_api_v3'));
    }

    public function test_cli_bare_override_wins_over_config_by_table(): void
    {
        $spec = TrimSpec::fromConfigAndCli($this->config(), ['log:different_col']);
        $this->assertSame(['column' => 'different_col', 'explicit' => true], $spec->columnFor('tenant_1', 'log'));
    }

    public function test_cli_qualified_beats_cli_bare(): void
    {
        $spec = TrimSpec::fromConfigAndCli([], ['x.logs:qual_col', 'logs:bare_col']);
        $this->assertSame(['column' => 'qual_col', 'explicit' => true], $spec->columnFor('x', 'logs'));
        $this->assertSame(['column' => 'bare_col', 'explicit' => true], $spec->columnFor('y', 'logs'));
    }

    public function test_cli_column_may_contain_no_colon_and_table_may_be_qualified(): void
    {
        $spec = TrimSpec::fromConfigAndCli([], ['db.tbl:col']);
        $this->assertSame(['column' => 'col', 'explicit' => true], $spec->columnFor('db', 'tbl'));
    }

    public function test_empty_config_and_cli_matches_nothing(): void
    {
        $spec = TrimSpec::fromConfigAndCli([], []);
        $this->assertNull($spec->columnFor('any', 'thing'));
        $this->assertTrue($spec->isEmpty());
    }

    public function test_is_empty_reports_whether_any_rule_exists(): void
    {
        $this->assertFalse(TrimSpec::fromConfigAndCli(['by_table' => ['log' => 'c']], [])->isEmpty());
        $this->assertFalse(TrimSpec::fromConfigAndCli([], ['log:c'])->isEmpty());
    }

    public function test_malformed_cli_entry_without_colon_is_ignored(): void
    {
        $spec = TrimSpec::fromConfigAndCli([], ['garbage_no_colon']);
        $this->assertTrue($spec->isEmpty());
    }
}
