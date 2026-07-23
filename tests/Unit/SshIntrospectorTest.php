<?php

namespace Mox3\Utils\Tests\Unit;

use Mox3\Utils\DbSync\SshIntrospector;
use PHPUnit\Framework\TestCase;

class SshIntrospectorTest extends TestCase
{
    /** @var list<string> */
    private array $commands = [];

    private function introspector(array $stdoutQueue): SshIntrospector
    {
        $this->commands = [];

        $runner = function (string $command) use (&$stdoutQueue): string {
            $this->commands[] = $command;

            return array_shift($stdoutQueue) ?? '';
        };

        return new SshIntrospector($runner, 'forge@host', 'root', 'pw');
    }

    public function test_base_tables_parses_newline_delimited_stdout(): void
    {
        $intro = $this->introspector(["logs_api_v3\nlog\nusers\n"]);

        $tables = $intro->baseTables('mortrac');

        $this->assertSame(['logs_api_v3', 'log', 'users'], $tables);
        // The query filters to BASE TABLE in the requested schema, shipped as base64.
        $expectedSql = "SELECT table_name FROM information_schema.tables "
            ."WHERE table_schema='mortrac' AND table_type='BASE TABLE'";
        $this->assertStringContainsString(base64_encode($expectedSql), $this->commands[0]);
    }

    public function test_column_types_parses_tab_separated_rows_into_nested_map(): void
    {
        $intro = $this->introspector([
            "logs_api_v3\ttimestamp\tdatetime\nlogs_api_v3\tid\tint\nusers\tid\tbigint\n",
        ]);

        $types = $intro->columnTypes('mortrac');

        $this->assertSame([
            'logs_api_v3' => ['timestamp' => 'datetime', 'id' => 'int'],
            'users' => ['id' => 'bigint'],
        ], $types);

        $expectedSql = 'SELECT table_name, column_name, data_type FROM information_schema.columns '
            ."WHERE table_schema='mortrac'";
        $this->assertStringContainsString(base64_encode($expectedSql), $this->commands[0]);
    }

    public function test_schema_name_is_sql_escaped(): void
    {
        $intro = $this->introspector(['']);
        $intro->baseTables("mo'rt");

        $expectedSql = "SELECT table_name FROM information_schema.tables "
            ."WHERE table_schema='mo''rt' AND table_type='BASE TABLE'";
        $this->assertStringContainsString(base64_encode($expectedSql), $this->commands[0]);
    }

    public function test_blank_lines_are_ignored(): void
    {
        $intro = $this->introspector(["\n\nlog\n\n"]);
        $this->assertSame(['log'], $intro->baseTables('x'));
    }
}
