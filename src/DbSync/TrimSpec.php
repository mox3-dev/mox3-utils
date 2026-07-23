<?php

namespace Mox3\Utils\DbSync;

/**
 * The merged "which tables to trim, and on which date column" specification,
 * built from the published `mox3-utils.trim_tables` config plus repeatable CLI
 * `--trim-table=table:column` overrides. Pure — no I/O, no introspection.
 *
 * Resolution precedence for a given (schema, table), first match wins:
 *   1. CLI qualified  (schema.table)         — explicit
 *   2. CLI bare       (table, any schema)    — explicit
 *   3. config qualified (schema.table)       — explicit
 *   4. config by_table (table, any schema    — non-explicit
 *      except exclude_schemas)
 *
 * `explicit` drives the missing-column policy in TrimPlanner: an explicit match
 * whose column is absent is a hard error; a non-explicit (bare) match is treated
 * as a coincidental name collision and dumped in full instead.
 */
final class TrimSpec
{
    /**
     * @param array<string, string> $cliQualified   schema.table => column
     * @param array<string, string> $cliBare        table => column
     * @param array<string, string> $configQualified schema.table => column
     * @param array<string, string> $configByTable  table => column
     * @param list<string>          $excludeSchemas schemas where by_table is skipped
     */
    private function __construct(
        private readonly array $cliQualified,
        private readonly array $cliBare,
        private readonly array $configQualified,
        private readonly array $configByTable,
        private readonly array $excludeSchemas,
    ) {}

    /**
     * @param array<string, mixed> $config    the `trim_tables` config array
     * @param list<string>         $cliTrimTables  repeatable --trim-table values (table:column)
     */
    public static function fromConfigAndCli(array $config, array $cliTrimTables): self
    {
        $cliQualified = [];
        $cliBare = [];

        foreach ($cliTrimTables as $entry) {
            if (! str_contains($entry, ':')) {
                continue; // malformed — no column
            }
            [$table, $column] = explode(':', $entry, 2);
            $table = trim($table);
            $column = trim($column);
            if ($table === '' || $column === '') {
                continue;
            }
            if (str_contains($table, '.')) {
                $cliQualified[$table] = $column;
            } else {
                $cliBare[$table] = $column;
            }
        }

        return new self(
            cliQualified: $cliQualified,
            cliBare: $cliBare,
            configQualified: self::stringMap($config['qualified'] ?? []),
            configByTable: self::stringMap($config['by_table'] ?? []),
            excludeSchemas: array_values(array_map('strval', (array) ($config['exclude_schemas'] ?? []))),
        );
    }

    /**
     * Resolve the trim date column for one table, or null if it is not trimmed.
     *
     * @return array{column: string, explicit: bool}|null
     */
    public function columnFor(string $schema, string $table): ?array
    {
        $qualified = "{$schema}.{$table}";

        if (isset($this->cliQualified[$qualified])) {
            return ['column' => $this->cliQualified[$qualified], 'explicit' => true];
        }
        if (isset($this->cliBare[$table])) {
            return ['column' => $this->cliBare[$table], 'explicit' => true];
        }
        if (isset($this->configQualified[$qualified])) {
            return ['column' => $this->configQualified[$qualified], 'explicit' => true];
        }
        if (isset($this->configByTable[$table]) && ! in_array($schema, $this->excludeSchemas, true)) {
            return ['column' => $this->configByTable[$table], 'explicit' => false];
        }

        return null;
    }

    /** True when no trim rule exists at all — trimming is a no-op. */
    public function isEmpty(): bool
    {
        return $this->cliQualified === []
            && $this->cliBare === []
            && $this->configQualified === []
            && $this->configByTable === [];
    }

    /** @return array<string, string> */
    private static function stringMap(mixed $map): array
    {
        $out = [];
        foreach ((array) $map as $key => $value) {
            $out[(string) $key] = (string) $value;
        }

        return $out;
    }
}
