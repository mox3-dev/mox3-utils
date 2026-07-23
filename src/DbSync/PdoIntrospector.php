<?php

namespace Mox3\Utils\DbSync;

/**
 * Introspects the source over a normal query connection (direct or tunnel
 * access). The actual query execution is injected so the shaping logic stays
 * unit-testable; the command wires this to a PDO connection built from the
 * source's effective host/port/credentials.
 *
 * Column aliases (t/c/d) are lowercased explicitly because some MySQL versions
 * return information_schema column names in upper case.
 */
final class PdoIntrospector implements SourceIntrospector
{
    /** @var callable(string, array): list<array<string, mixed>> */
    private $query;

    /**
     * @param callable(string, array): list<array<string, mixed>> $query
     *        runs a prepared statement, returns rows as associative arrays
     */
    public function __construct(callable $query)
    {
        $this->query = $query;
    }

    public function baseTables(string $schema): array
    {
        $rows = ($this->query)(
            'SELECT table_name AS t FROM information_schema.tables '
            ."WHERE table_schema = ? AND table_type = 'BASE TABLE'",
            [$schema]
        );

        return array_map(static fn (array $row): string => (string) $row['t'], $rows);
    }

    public function columnTypes(string $schema): array
    {
        $rows = ($this->query)(
            'SELECT table_name AS t, column_name AS c, data_type AS d '
            .'FROM information_schema.columns WHERE table_schema = ?',
            [$schema]
        );

        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row['t']][(string) $row['c']] = (string) $row['d'];
        }

        return $map;
    }
}
