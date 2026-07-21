# Design: opt-in log-table trimming for `db:sync-production`

**Date:** 2026-07-20
**Status:** Approved (design), pending implementation plan

## Problem

`db:sync-production` dumps each source database in full — one `mysqldump | gzip`
pass over the whole schema. That is fine for normal databases but makes large
legacy log databases impractical to clone: a full dump of a schema dominated by
dead log tables (e.g. `logs_api_v3`, `logs_app`) can be hundreds of GB.

The command this package replaced (`app:clone-database` in the MorTrack app) solved
this by dumping log tables through a `--where` date filter — only the last N days of
rows crossed, every other table dumped in full. That capability was lost when the
app adopted `db:sync-production`. We want it back **in the package**, so it is
reusable rather than re-forked per app.

This is a dump-side, read-only filter. The source database is never modified.

## Non-goals / hard constraint

**The package must NOT hardcode which tables to trim.** The old command hardcoded
MorTrack-specific maps; those belong to the consuming app. mox3-utils supplies the
*mechanism*; the app supplies the *table → date-column map* via published config
and/or CLI. With no config and no `--trim-logs`, behavior is byte-identical to
today.

## Decisions (resolved during brainstorming)

1. **SSH introspection: supported.** `--trim-logs` works across all three access
   modes (`direct`, `tunnel`, `ssh`). For `ssh`, the two `information_schema`
   queries run over the same ssh channel using the base64 option-file-over-stdin
   pattern that `sshDumpCommand` already uses (credentials never touch argv).

2. **`by_table` scoping: denylist.** A bare-name (`by_table`) match applies in any
   source schema **except** those listed in `exclude_schemas`. This fits
   dynamically-named tenant databases (their names can't be enumerated ahead of
   time) while letting the app exempt known non-tenant schemas such as `pti_dw`.

3. **Missing date column under `--trim-logs`: qualified→error, bare-name→warn+full.**
   A `qualified` config entry or a CLI `--trim-table` naming a column that does not
   exist in that schema is a hard error (explicit config must be correct). A
   `by_table` match whose column is absent in a particular schema is treated as a
   coincidental name collision: warn loudly and dump that one table in full.

## New CLI options on `db:sync-production`

- `--trim-logs` — enable trimming. Off by default (full dump stays the default).
- `--log-days=N` — retention window in days (default `30`). Only meaningful with
  `--trim-logs`. Day granularity only (no `--log-months`).
- `--trim-table=table:column` — repeatable ad-hoc override. `table` may be
  qualified (`db.table`) or bare. CLI entries win over config. Treated as
  `explicit` for the missing-column policy.

## Published config

`config/mox3-utils.php`, merged and publishable via the service provider:

```php
return [
    'trim_tables' => [
        // qualified: database.table => date column (only trims in that schema)
        'qualified' => [
            // 'mortrac.logs_api_v3' => 'timestamp',
        ],
        // by bare table name, applied in any schema not excluded below
        'by_table' => [
            // 'log'           => 'createdOn',
            // 'pulse_entries' => 'timestamp', // int epoch — cutoff uses UNIX_TIMESTAMP()
        ],
        // schemas where by_table matches are NOT applied (e.g. non-tenant schemas)
        'exclude_schemas' => [
            // 'pti_dw',
        ],
    ],
];
```

The app-specific maps (MorTrack's `logs_*`, tenant `log`, pulse epoch columns, etc.)
live in the **consuming app's** copy of this config, never in the package.

## Module boundaries

### New

**`DbSync/TrimSpec`** — pure resolver, no I/O.
- Built from the published `trim_tables` config plus the repeatable CLI
  `--trim-table=table:column` list.
- `columnFor(string $schema, string $table): ?array` returns
  `['column' => string, 'explicit' => bool]` or `null`.
- Precedence (first match wins):
  1. CLI qualified (`schema.table`)
  2. CLI bare (`table`)
  3. config `qualified['schema.table']`
  4. config `by_table['table']` — skipped when `$schema` is in `exclude_schemas`
- `explicit` is `true` for matches 1–3, `false` for match 4 (drives the
  missing-column policy).
- Fully unit-testable.

**`DbSync/SourceIntrospector`** — interface for reading source metadata:
- `baseTables(string $schema): list<string>` — `BASE TABLE`s in the schema.
- `columnTypes(string $schema): array<string, array<string, string>>` — one
  `information_schema.columns` query for the whole schema, returned as
  `table => (column => data_type)`. One metadata query per schema; no per-table
  round-trips and no table-name interpolation into SQL.

Two implementations:
- **`PdoIntrospector`** (direct / tunnel) — raw PDO to `effectiveHost:effectivePort`
  with the source username/password; parameterized `information_schema` queries.
  For `tunnel`, the tunnel is already open (opened in `handle()` before the dump
  loop) so PDO connects to `127.0.0.1:<localPort>`.
- **`SshIntrospector`** (ssh) — runs `mysql -N -e "$SQL"` over ssh via
  `ShellCommands::sshQueryCommand(...)`, reusing the base64 option-file-over-stdin
  pattern; parses tab-separated / newline-delimited stdout into rows.

**`DbSync/TrimPlanner`** — takes a `TrimSpec` and a `SourceIntrospector`; returns
`array<string $table, string $whereClause>`.
- For each base table, ask `TrimSpec::columnFor`. Skip if `null` (business table →
  full dump).
- Look up `data_type` from the introspector's `columnTypes` map.
- Missing column: `explicit` → throw `RuntimeException`; non-explicit (`by_table`)
  → warn (`⚠ <table>: configured trim column '<col>' not found — dumping in FULL`)
  and omit from the map (omitted ⇒ not ignored in pass 1 ⇒ dumped in full).
- Present column: `where = ShellCommands::retentionCutoffSql(col, dataType, days)`.
- Emits a per-table `trimming <table> (last N days)` line as it plans.

### Changed — `DbSync/ShellCommands`

- `retentionCutoffSql(string $column, string $dataType, int $days): string`
  ```php
  $isEpoch = in_array(strtolower($dataType),
      ['int','bigint','smallint','mediumint','tinyint','decimal','float','double'], true);
  $cutoff = $isEpoch
      ? "UNIX_TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL {$days} DAY))"
      : "DATE_SUB(CURDATE(), INTERVAL {$days} DAY)";
  return "`{$column}` >= {$cutoff}";
  ```
- `directDumpCommand(...)` and `sshDumpCommand(...)` gain an `$ignoreTables` list
  parameter (default `[]`):
  - Empty list ⇒ the produced command string is **byte-identical** to today.
  - Non-empty: pass 1 adds one `--ignore-table=<db>.<table>` per entry.
  - direct: `--ignore-table=` + `escapeshellarg("$db.$table")`.
  - ssh: the ignore list travels as a base64 newline-joined blob decoded into a
    bash array inside the heredoc, so table-name bytes never sit literally in the
    heredoc body (same rigor as the existing database-name handling).
- Append builders (pass 2): `mysqldump <appendOpts> --where=<where> <db> <table>
  | gzip >> <dumpFile>`.
  - direct: `escapeshellarg` on where/db/table.
  - ssh: where/db/table shipped as base64 vars; `gzip >>` runs locally.
  - `appendOpts` = base opts (`--single-transaction --quick --no-tablespaces
    --set-gtid-purged=OFF --default-character-set=utf8mb4`) plus `--no-create-info`
    **only** when `--data-only`. Never re-emits `--routines/--triggers/--events`
    (those came from pass 1). When not `--data-only`, the append re-adds the
    trimmed table's own `CREATE TABLE` + filtered `INSERT`s.
- `sshQueryCommand(string $sshTarget, string $username, string $password,
  string $sql): string` — for `SshIntrospector`; ships creds via the base64 option
  file and the SQL as a base64 var, runs `mysql --defaults-extra-file="$CNF" -N -e
  "$SQL"`, prints tab-separated rows to stdout.

### Changed — `SyncProductionDatabase`

- Register `--trim-logs`, `--log-days=30`, `--trim-table=*`.
- `dump($source, $dumpFile)`:
  - If `--trim-logs`: build `TrimSpec` from config + CLI; choose the introspector
    by access mode (`PdoIntrospector` for direct/tunnel, `SshIntrospector` for
    ssh); run `TrimPlanner::plan()` → `{table => where}`; run the main pass with
    the ignore list; **guard: assert the dump file exists and is non-empty before
    starting appends**; then loop the append passes.
  - Else: the current single full-dump path, unchanged.
- `printSummary()` adds a `Log tables` row: `trimmed to last N days` vs `full`.

### Changed — `Mox3UtilsServiceProvider`

- `mergeConfigFrom(__DIR__.'/../config/mox3-utils.php', 'mox3-utils')`.
- `publishes([... => config_path('mox3-utils.php')], 'mox3-utils-config')` when
  running in console.

## Data flow (trim path)

```
dump(srcDb)
  → TrimSpec::fromConfigAndCli(config('mox3-utils.trim_tables'), --trim-table[])
  → introspector = PdoIntrospector | SshIntrospector   (by access mode)
  → TrimPlanner(spec, introspector).plan(srcDb, days)  → { table => whereClause }
  → main pass:   mysqldump <opts> --ignore-table=… | gzip >  dump.sql.gz
  → guard:       dump.sql.gz exists && size > 0
  → per table:   mysqldump <appendOpts> --where=… srcDb table | gzip >> dump.sql.gz
  → import path (unchanged): gunzip handles the multi-member stream transparently;
    the DEFINER-strip (and optional FK-strip) filters run over the whole stream.
```

Multi-member gzip: two or more concatenated `gzip` streams decompress as one via
`gunzip`, and each appended `mysqldump` emits its own complete
header + `CREATE TABLE` + `INSERT` block, so the concatenation is a single valid
SQL dump.

## Error handling

- Source unreachable / introspection query failure → clear error; the existing
  per-database `try/catch` in `handle()` isolates one database's failure from the
  rest of the batch.
- Explicit trim column missing (qualified config or CLI) → `RuntimeException`,
  aborting that database before it dumps.
- Empty dump file after pass 1 → abort before any append (never leave a half-dump).
- The final non-empty check after the whole dump is retained.

## Tests

**Unit**
- `retentionCutoffSql`: datetime column → `DATE_SUB(...)`; epoch int/bigint/decimal
  → `UNIX_TIMESTAMP(DATE_SUB(...))`; column name backtick-quoted.
- `directDumpCommand`: ignore list → one `--ignore-table='db.table'` per entry;
  **empty list ⇒ byte-identical to the current output** (single pass, no
  `--ignore-table`).
- direct append command: contains `--where=`, the table name, and `gzip >>`.
- `sshDumpCommand` with ignore list: base64 ignore payload present, no literal
  table bytes in the heredoc, still `gzip >`; heredoc terminator count unchanged.
- ssh append command: `gzip >>`, base64 where/table, no plaintext.
- `sshQueryCommand`: base64 creds + base64 SQL, `-N -e`, no plaintext password,
  `mysql --defaults-extra-file="$CNF"`.
- `TrimSpec`: CLI beats config; qualified beats bare; `exclude_schemas` suppresses
  a `by_table` match in that schema; `explicit` flag correct per source.
- `TrimPlanner` with a fake introspector: builds the where map; explicit missing
  column → throws; bare missing column → warns and is omitted from the map.

**Feature**
- Existing `SyncProductionDatabaseGuardTest` stays green.
- Option wiring: `--trim-logs` / `--log-days` / `--trim-table` parse and reach the
  summary; no-trim run adds no `--ignore-table` (asserted at the `ShellCommands`
  level, since the command shells out for the actual dump).

## Release

- No `version` field in `composer.json` — the package versions via git tags. Tag a
  new **minor** version after merge.
- Document the new `--trim-logs` / `--log-days` / `--trim-table` options and the
  `trim_tables` config key (with the publish command) in `db-sync/README.md`,
  including the introspection note (works over direct/tunnel/ssh) and the
  missing-column policy.
- Follow-up (in the app, not this package): bump the constraint and add the
  `trim_tables` config.
```
