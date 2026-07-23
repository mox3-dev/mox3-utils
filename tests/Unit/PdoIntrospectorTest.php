<?php

namespace Mox3\Utils\Tests\Unit;

use Mox3\Utils\DbSync\PdoIntrospector;
use PHPUnit\Framework\TestCase;

class PdoIntrospectorTest extends TestCase
{
    /** @var list<array{sql: string, params: array}> */
    private array $queries = [];

    private function introspector(array $rowsQueue): PdoIntrospector
    {
        $this->queries = [];

        $query = function (string $sql, array $params) use (&$rowsQueue): array {
            $this->queries[] = ['sql' => $sql, 'params' => $params];

            return array_shift($rowsQueue) ?? [];
        };

        return new PdoIntrospector($query);
    }

    public function test_base_tables_selects_base_tables_in_schema(): void
    {
        $intro = $this->introspector([
            [['t' => 'logs'], ['t' => 'users']],
        ]);

        $this->assertSame(['logs', 'users'], $intro->baseTables('mortrac'));

        $this->assertStringContainsString('information_schema.tables', $this->queries[0]['sql']);
        $this->assertStringContainsString("table_type = 'BASE TABLE'", $this->queries[0]['sql']);
        $this->assertSame(['mortrac'], $this->queries[0]['params']);
    }

    public function test_column_types_builds_nested_map_from_rows(): void
    {
        $intro = $this->introspector([
            [
                ['t' => 'logs', 'c' => 'timestamp', 'd' => 'datetime'],
                ['t' => 'logs', 'c' => 'id', 'd' => 'int'],
                ['t' => 'users', 'c' => 'id', 'd' => 'bigint'],
            ],
        ]);

        $this->assertSame([
            'logs' => ['timestamp' => 'datetime', 'id' => 'int'],
            'users' => ['id' => 'bigint'],
        ], $intro->columnTypes('mortrac'));

        $this->assertStringContainsString('information_schema.columns', $this->queries[0]['sql']);
        $this->assertSame(['mortrac'], $this->queries[0]['params']);
    }
}
