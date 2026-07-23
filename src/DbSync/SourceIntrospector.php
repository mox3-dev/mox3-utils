<?php

namespace Mox3\Utils\DbSync;

/**
 * Reads structural metadata from the SOURCE database so the trim planner knows
 * which tables exist and the data type of each candidate date column. One
 * implementation per access mode (PDO for direct/tunnel, SSH-exec for ssh).
 */
interface SourceIntrospector
{
    /**
     * Base tables (not views) in the schema.
     *
     * @return list<string>
     */
    public function baseTables(string $schema): array;

    /**
     * Column data types for the whole schema, as table => (column => data_type),
     * fetched in a single information_schema query.
     *
     * @return array<string, array<string, string>>
     */
    public function columnTypes(string $schema): array;
}
