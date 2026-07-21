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
```

Note: the `--target-connection` for `--push-remote` must use `direct` or `tunnel`
access — `ssh`-access targets are rejected (a secure remote import cannot keep
credentials off the command line over ssh-exec). Use `tunnel` access to reach a
remote target's database.

Options: `--source-connection=` (default `production`), `--target-connection=`,
`--dump-only`, `--push-remote`, `--keep-dump`, `--backup`, `--data-only`,
`--databases=*` (repeatable/comma-separated; `db` or `source:target`),
`--strip-foreign-keys`, `--force`.

With `--databases`, every DB is synced independently: one dump file each, a
failure on one does not abort the rest, and a per-database result table is
printed at the end (the command exits non-zero if any database failed). DEFINER
clauses are always stripped on import; `--strip-foreign-keys` additionally drops
`CONSTRAINT … FOREIGN KEY` DDL for targets on MySQL 8.4+/9.x that reject legacy
foreign keys referencing non-unique columns.

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

## WordPress / non-Laravel: `sync-db.sh`

A framework-agnostic bash script driven by a `.db-sync.conf` file. See the script
header for configuration. (Note: `sync-db.sh` and `.db-sync.conf.example` are being
restored separately.)

## License

MIT
