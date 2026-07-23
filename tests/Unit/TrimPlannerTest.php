<?php

namespace Mox3\Utils\Tests\Unit;

use Mox3\Utils\DbSync\SourceIntrospector;
use Mox3\Utils\DbSync\TrimPlanner;
use Mox3\Utils\DbSync\TrimSpec;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class TrimPlannerTest extends TestCase
{
    private function introspector(array $baseTables, array $columnTypes): SourceIntrospector
    {
        return new class($baseTables, $columnTypes) implements SourceIntrospector
        {
            public function __construct(private array $tables, private array $types) {}

            public function baseTables(string $schema): array
            {
                return $this->tables;
            }

            public function columnTypes(string $schema): array
            {
                return $this->types;
            }
        };
    }

    public function test_builds_where_clauses_for_matched_tables_only(): void
    {
        $spec = TrimSpec::fromConfigAndCli([
            'qualified' => ['mortrac.logs_api_v3' => 'timestamp'],
        ], []);

        $planner = new TrimPlanner($spec, $this->introspector(
            ['logs_api_v3', 'users', 'orders'],
            [
                'logs_api_v3' => ['timestamp' => 'datetime'],
                'users' => ['id' => 'int'],
            ],
        ));

        $plan = $planner->plan('mortrac', 30);

        $this->assertSame(
            ['logs_api_v3' => '`timestamp` >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)'],
            $plan->wheres
        );
        $this->assertSame([], $plan->warnings);
    }

    public function test_epoch_column_uses_unix_timestamp_cutoff(): void
    {
        $spec = TrimSpec::fromConfigAndCli(['by_table' => ['pulse_entries' => 'timestamp']], []);
        $planner = new TrimPlanner($spec, $this->introspector(
            ['pulse_entries'],
            ['pulse_entries' => ['timestamp' => 'int']],
        ));

        $plan = $planner->plan('app', 30);
        $this->assertSame(
            '`timestamp` >= UNIX_TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 30 DAY))',
            $plan->wheres['pulse_entries']
        );
    }

    public function test_explicit_missing_column_is_a_hard_error(): void
    {
        $spec = TrimSpec::fromConfigAndCli(['qualified' => ['mortrac.logs' => 'timestamp']], []);
        $planner = new TrimPlanner($spec, $this->introspector(
            ['logs'],
            ['logs' => ['id' => 'int']], // no `timestamp` column
        ));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('timestamp');
        $planner->plan('mortrac', 30);
    }

    public function test_bare_missing_column_warns_and_is_dumped_in_full(): void
    {
        $spec = TrimSpec::fromConfigAndCli(['by_table' => ['notifications' => 'created_at']], []);
        $planner = new TrimPlanner($spec, $this->introspector(
            ['notifications'],
            ['notifications' => ['id' => 'int']], // no `created_at` in this schema
        ));

        $plan = $planner->plan('pti_dw_like', 30);

        $this->assertArrayNotHasKey('notifications', $plan->wheres);
        $this->assertCount(1, $plan->warnings);
        $this->assertStringContainsString('notifications', $plan->warnings[0]);
        $this->assertStringContainsString('created_at', $plan->warnings[0]);
        $this->assertStringContainsStringIgnoringCase('full', $plan->warnings[0]);
    }

    public function test_tables_helper_lists_trimmed_tables(): void
    {
        $spec = TrimSpec::fromConfigAndCli(['by_table' => ['log' => 'createdOn']], []);
        $planner = new TrimPlanner($spec, $this->introspector(
            ['log', 'users'],
            ['log' => ['createdOn' => 'datetime']],
        ));

        $plan = $planner->plan('tenant', 15);
        $this->assertSame(['log'], $plan->tables());
    }
}
