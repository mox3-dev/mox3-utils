<?php

namespace Mox3\Utils\DbSync;

use RuntimeException;

/**
 * Turns a TrimSpec + live source introspection into a concrete TrimPlan: which
 * tables to trim and the WHERE clause for each. Applies the missing-column
 * policy — an explicit (qualified/CLI) match with an absent column is a hard
 * error; a bare-name match with an absent column warns and is dumped in full.
 */
final class TrimPlanner
{
    public function __construct(
        private readonly TrimSpec $spec,
        private readonly SourceIntrospector $introspector,
    ) {}

    public function plan(string $schema, int $days): TrimPlan
    {
        $columnTypes = $this->introspector->columnTypes($schema);

        $wheres = [];
        $warnings = [];

        foreach ($this->introspector->baseTables($schema) as $table) {
            $match = $this->spec->columnFor($schema, $table);
            if ($match === null) {
                continue; // business table — full dump
            }

            $column = $match['column'];
            $dataType = $columnTypes[$table][$column] ?? null;

            if ($dataType === null) {
                if ($match['explicit']) {
                    throw new RuntimeException(
                        "trim column `{$column}` not found on `{$schema}`.`{$table}` — "
                        .'refusing to dump it in full under --trim-logs (fix the config/--trim-table).'
                    );
                }

                $warnings[] = "⚠ {$table}: configured trim column `{$column}` not found — dumping in FULL.";

                continue;
            }

            $wheres[$table] = ShellCommands::retentionCutoffSql($column, $dataType, $days);
        }

        return new TrimPlan($wheres, $warnings);
    }
}
