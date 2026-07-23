# Database Sync Utility

Two ways to pull a remote MySQL database down to local (and, for Laravel, push it
to a remote target):

- **Laravel projects** — the `db:sync-production` Artisan command, shipped by the
  `mox3-dev/mox3-utils` Composer package.
- **WordPress / non-Laravel projects** — the `sync-db.sh` bash script (config-file driven).

## Laravel: `db:sync-production` (Composer package)

### Install (per project, one-time)

Add the repository and require the package:

```jsonc
// composer.json
"repositories": [
    { "type": "vcs", "url": "git@github.com:mox3-dev/mox3-utils.git" }
]
```

```bash
composer require mox3-dev/mox3-utils
```

The command auto-registers via Laravel package discovery — no `config/app.php` edit needed.

### Configure the source (and optional target) connection

Add a `production` connection in `config/database.php` (and a `staging` one if you
will use `--push-remote`), then set the connection + access details in `.env`.

```php
'production' => [
    'driver' => 'mysql',
    'host' => env('PRODUCTION_DB_HOST', '127.0.0.1'),
    'port' => env('PRODUCTION_DB_PORT', '3306'),
    'database' => env('PRODUCTION_DB_DATABASE'),
    'username' => env('PRODUCTION_DB_USERNAME'),
    'password' => env('PRODUCTION_DB_PASSWORD'),
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
],
```

### Access modes (per connection, via `.env`)

`<CONN>_ACCESS` chooses how the connection is reached (`<CONN>` is the connection
name upper-cased with `-` → `_`, e.g. `production` → `PRODUCTION`).

**Cloudways / Forge (`ssh` — run mysqldump on the remote host):**
```env
PRODUCTION_ACCESS=ssh
PRODUCTION_SSH=forge@your-server-ip
PRODUCTION_DB_DATABASE=your_db
PRODUCTION_DB_USERNAME=your_db_user
PRODUCTION_DB_PASSWORD=your_db_pass
```

**Tunnel (`tunnel` — auto-open an SSH tunnel, e.g. mortrack):**
```env
PRODUCTION_ACCESS=tunnel
PRODUCTION_TUNNEL_SSH=bastion-user@ec2-host
PRODUCTION_TUNNEL_REMOTE=db.internal:3306
PRODUCTION_TUNNEL_LOCAL_PORT=13306
PRODUCTION_DB_DATABASE=your_db
PRODUCTION_DB_USERNAME=your_db_user
PRODUCTION_DB_PASSWORD=your_db_pass
```

**Direct (`direct` — default; local mysqldump straight at host/port):**
```env
PRODUCTION_ACCESS=direct
PRODUCTION_DB_HOST=127.0.0.1
PRODUCTION_DB_PORT=3306
```

### Usage

```bash
# Mode 1 — just save a dump file (storage/app/exports/*.sql.gz)
php artisan db:sync-production --dump-only

# Mode 2 — dump remote → drop/recreate LOCAL → import (the default)
php artisan db:sync-production
php artisan db:sync-production --backup      # back up local first
php artisan db:sync-production --data-only   # skip table structure
php artisan db:sync-production --force        # skip the confirmation

# Mode 3 — dump remote → drop/recreate a REMOTE target → import (guarded)
php artisan db:sync-production --push-remote --target-connection=staging --force

# Multiple databases in one run (repeatable or comma-separated).
# Each target takes the source name unless you map it with source:target.
php artisan db:sync-production --databases=app,billing
php artisan db:sync-production --databases=prod_app:local_app --databases=prod_logs:local_logs

# Legacy schemas whose FKs reference non-unique columns (rejected by MySQL 8.4+/9.x)
php artisan db:sync-production --strip-foreign-keys

# Trim big log tables to the last N days instead of dumping them in full
php artisan db:sync-production --trim-logs                       # last 30 days (default)
php artisan db:sync-production --trim-logs --log-days=7          # last 7 days
php artisan db:sync-production --trim-logs --trim-table=logs_api_v3:timestamp
```

Note: the `--target-connection` for `--push-remote` must use `direct` or `tunnel`
access — `ssh`-access targets are rejected (a secure remote import cannot keep
credentials off the command line over ssh-exec). Use `tunnel` access to reach a
remote target's database.

Options: `--source-connection=` (default `production`), `--target-connection=`,
`--dump-only`, `--push-remote`, `--keep-dump`, `--backup`, `--data-only`,
`--databases=*` (repeatable/comma-separated; `db` or `source:target`),
`--strip-foreign-keys`, `--trim-logs`, `--log-days=` (default `30`),
`--trim-table=*` (repeatable; `table:column` or `db.table:column`), `--force`.

With `--databases`, every DB is synced independently: one dump file each, a
failure on one does not abort the rest, and a per-database result table is
printed at the end (the command exits non-zero if any database failed). DEFINER
clauses are always stripped on import; `--strip-foreign-keys` additionally drops
`CONSTRAINT … FOREIGN KEY` DDL for targets on MySQL 8.4+/9.x that reject legacy
foreign keys referencing non-unique columns.

### Trimming large log tables (`--trim-logs`)

Some legacy schemas are dominated by dead log tables whose full dump is
impractical (hundreds of GB). `--trim-logs` dumps those tables filtered to the
last `--log-days` days (default `30`) while every other table dumps in full. It is
a read-only, dump-side filter — the source is never modified. Off by default; with
no configuration it is a no-op and the dump is identical to a full dump.

Mechanically it is a two-pass dump into one gzip file: pass 1 dumps the whole DB
with `--ignore-table` for each trimmed table; pass 2 appends each trimmed table
with a `--where` date filter. Integer/decimal date columns are treated as Unix
epoch seconds automatically. Introspection (to discover tables and column types)
runs over whichever access mode the source uses — `direct`, `tunnel`, **and**
`ssh` (the metadata queries run over the same SSH channel as the dump).

**The package does not hardcode which tables to trim — the consuming app supplies
the map.** Publish and edit the config:

```bash
php artisan vendor:publish --tag=mox3-utils-config
```

```php
// config/mox3-utils.php
'trim_tables' => [
    // Qualified `database.table => date column` — only trims that exact schema.
    'qualified' => [
        'mortrac.logs_api_v3' => 'timestamp',
        'mortrac.user_time'   => 'login',
    ],
    // Bare `table => date column` — applies in any source schema not excluded
    // below (handy for per-tenant `log` tables with unknown database names).
    'by_table' => [
        'log'           => 'createdOn',
        'pulse_entries' => 'timestamp', // int epoch
    ],
    // Schemas where `by_table` matches are skipped (does not affect `qualified`).
    'exclude_schemas' => ['pti_dw'],
],
```

Resolution precedence (first match wins): CLI `--trim-table` qualified → CLI
`--trim-table` bare → config `qualified` → config `by_table`. `--trim-table` is
repeatable and takes `table:column` or `db.table:column`.

Note: the main pass and each appended table are separate `mysqldump` invocations,
so each gets its own `--single-transaction` snapshot — a trimmed dump is not a
single point-in-time image across all tables (fine for append-only log tables,
which is what trimming targets).

**Missing-column policy:** under `--trim-logs`, if a matched table's configured
date column does not exist in that schema, an *explicit* match (a `qualified`
entry or a `--trim-table`) is a hard error (fix the config), while a *bare-name*
(`by_table`) match is treated as a coincidental name collision — it warns
(`⚠ <table>: configured trim column … not found — dumping in FULL`) and dumps that
one table in full.

### Safety

- Credentials for local `mysqldump`/`mysql` go through a temporary 0600
  `--defaults-extra-file`, never on the command line. The `ssh` path sends the
  remote option file over SSH stdin, so no password appears in any process list.
- `--push-remote` is destructive on a remote server: it requires `--force`, prints
  the exact host/DB to be dropped, and refuses when the target host:port + database
  equals the source being dumped.

### Verification checklist

- `--dump-only` produces a non-empty `.sql.gz` in `storage/app/exports/`.
- Local import round-trips: source and local base-table counts match.
- `--push-remote` without `--force` (or without `--target-connection`) refuses.
- While a dump runs, `ps aux | grep mysqldump` shows no password.
- `tunnel` access opens the tunnel, dumps, then leaves no lingering `ssh -L` process.
- `--trim-logs` prints a `trimming <table> (last N days)` line per trimmed table and
  a `Log tables: trimmed to last N days` summary row; the resulting dump imports and
  the trimmed tables hold only rows within the window.

## WordPress / non-Laravel: `sync-db.sh`

A framework-agnostic bash script driven by a `.db-sync.conf` file. See the script
header for configuration. (Note: `sync-db.sh` and `.db-sync.conf.example` are being
restored separately.)

## License

MIT
