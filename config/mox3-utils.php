<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Log-table trimming for db:sync-production --trim-logs
    |--------------------------------------------------------------------------
    |
    | The package supplies the *mechanism* for trimming large log tables to the
    | last N days at dump time; the consuming app supplies the *table → date
    | column* map here. With nothing configured (and no --trim-table), --trim-logs
    | is a no-op and the dump is byte-identical to a full dump.
    |
    | Resolution precedence (first match wins): CLI --trim-table qualified,
    | CLI --trim-table bare, config `qualified`, config `by_table`.
    |
    | Integer/decimal date columns are treated as Unix epoch seconds
    | automatically (the cutoff is wrapped in UNIX_TIMESTAMP()).
    |
    */

    'trim_tables' => [

        // Qualified `database.table => date column`. Only trims that exact schema.
        'qualified' => [
            // 'mortrac.logs_api_v3' => 'timestamp',
            // 'mortrac.user_time'   => 'login',
        ],

        // Bare `table => date column`. Applies in any source schema NOT listed in
        // `exclude_schemas` below — useful for per-tenant `log` tables whose
        // database names aren't known ahead of time.
        'by_table' => [
            // 'log'           => 'createdOn',
            // 'pulse_entries' => 'timestamp', // int epoch
        ],

        // Schemas where `by_table` matches are NOT applied (e.g. a warehouse
        // schema that happens to have a same-named table). Does not affect
        // `qualified` entries.
        'exclude_schemas' => [
            // 'pti_dw',
        ],

    ],

];
