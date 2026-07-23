<?php

namespace Mox3\Utils\DbSync;

/**
 * The resolved outcome of planning a trim: the WHERE clause for each table that
 * will be trimmed, plus any warnings (bare-name matches whose configured column
 * was absent and are therefore dumped in full).
 */
final class TrimPlan
{
    /**
     * @param array<string, string> $wheres   table => WHERE clause
     * @param list<string>          $warnings human-readable warnings
     */
    public function __construct(
        public readonly array $wheres,
        public readonly array $warnings,
    ) {}

    /**
     * Tables that will be trimmed (excluded from the main pass, re-appended with
     * a filter).
     *
     * @return list<string>
     */
    public function tables(): array
    {
        return array_keys($this->wheres);
    }

    public function isEmpty(): bool
    {
        return $this->wheres === [];
    }
}
