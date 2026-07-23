<?php

namespace Mox3\Utils\DbSync;

/**
 * Introspects the source over the same SSH channel used for dumping — there is
 * no local socket to the remote DB. Runs `mysql -N -e` queries via
 * ShellCommands::sshQueryCommand (credentials + SQL shipped as base64) and parses
 * the tab/newline-delimited stdout.
 */
final class SshIntrospector implements SourceIntrospector
{
    /** @var callable(string): string */
    private $runner;

    /**
     * @param callable(string): string $runner runs a shell command, returns stdout
     */
    public function __construct(
        callable $runner,
        private readonly string $sshTarget,
        private readonly string $username,
        private readonly string $password,
    ) {
        $this->runner = $runner;
    }

    public function baseTables(string $schema): array
    {
        $sql = 'SELECT table_name FROM information_schema.tables '
            ."WHERE table_schema='".self::sqlLiteral($schema)."' AND table_type='BASE TABLE'";

        $tables = [];
        foreach (explode("\n", $this->run($sql)) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $tables[] = $line;
            }
        }

        return $tables;
    }

    public function columnTypes(string $schema): array
    {
        $sql = 'SELECT table_name, column_name, data_type FROM information_schema.columns '
            ."WHERE table_schema='".self::sqlLiteral($schema)."'";

        $map = [];
        foreach (explode("\n", $this->run($sql)) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $cols = explode("\t", $line);
            if (count($cols) < 3) {
                continue;
            }
            [$table, $column, $type] = $cols;
            $map[$table][$column] = $type;
        }

        return $map;
    }

    private function run(string $sql): string
    {
        $command = ShellCommands::sshQueryCommand($this->sshTarget, $this->username, $this->password, $sql);

        return ($this->runner)($command);
    }

    /** Escape a value for embedding inside a single-quoted MySQL string literal. */
    private static function sqlLiteral(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "''"], $value);
    }
}
