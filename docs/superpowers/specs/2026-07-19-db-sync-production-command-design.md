# Design: `db:sync-production` — remote dump / import command (shared via Composer)

**Date:** 2026-07-19
**Status:** Approved for planning
**Package:** `mox3-dev/mox3-utils`

## Summary

Evolve the existing `db:sync-production` Artisan command (currently remote → local only,
living as a loose file in `db-sync/`) into a single command with **three modes**, and
distribute it to all Laravel sites as an auto-registered **Composer package** so each site
configures it purely through its own `.env`.

The design generalizes the capable `app:clone-database` command from the `mortrack` project
(dump-from-source → import-into-target, credential-safe, verified) while keeping the current
command's SSH-based dumping and adding auto-managed SSH tunnels for tunnel-only projects.

## Goals

- One command, three modes selected by flags:
  1. **Save dump only** (`--dump-only`) — write a `.sql.gz` file, touch no database.
  2. **Import to local** (default) — dump remote, drop/recreate the local DB, import.
  3. **Import to remote** (`--push-remote`) — dump remote, drop/recreate a *target* remote DB, import.
- Usable across all **Laravel** sites without copying code, configured per-project via `.env`.
- Reach source/target DBs three ways: `direct`, `ssh` (mysqldump-over-SSH), or `tunnel` (auto-opened SSH tunnel).
- Safe by default: credentials never on the command line; destructive remote pushes strongly guarded.

## Non-goals (YAGNI)

- No multi-database / batch runs — one database per invocation.
- No mortrack-specific log-table trimming maps or `mortrack.app` production detection.
- No persistent/background tunnels — a tunnel is opened and torn down within one run.
- WordPress sites are out of scope for the Artisan command; they continue to use `sync-db.sh`.

## Distribution model

`mox3-utils` becomes a proper Composer package:

- `composer.json` with PSR-4 autoload under `Mox3\Utils\` and a Laravel-auto-discovered
  ServiceProvider that registers the command.
- The command class moves to `src/Console/Commands/SyncProductionDatabase.php` (namespaced),
  superseding the loose `db-sync/SyncProductionDatabase.php`.
- `sync-db.sh` (framework-agnostic bash) remains for WordPress sites (`eooc`, `inspiremd`,
  `mmet`, `mymetalbc`) that cannot run Artisan.

Per Laravel site (one-time setup):

```jsonc
// composer.json
"repositories": [{ "type": "vcs", "url": "git@github.com:mox3-dev/mox3-utils.git" }]
```
```bash
composer require mox3-dev/mox3-utils
```

The command resolves connections from `config('database.connections.*')`, which each site
backs with its own `.env`. `composer update` propagates fixes to every site.

## Command interface

```
php artisan db:sync-production
    {--source-connection=production : Laravel connection for the SOURCE (remote) DB}
    {--target-connection=           : Target connection for --push-remote (e.g. staging)}
    {--dump-only                    : Mode 1 — dump to a file, touch no database}
    {--push-remote                  : Mode 3 — import into --target-connection instead of local}
    {--keep-dump                    : Keep the dump file after import}
    {--backup                       : Back up the destination DB before overwriting it}
    {--data-only                    : Dump data only (skip CREATE TABLE structure)}
    {--force                        : Skip the destructive-action confirmation}
```

Usage:

```bash
php artisan db:sync-production                                   # dump remote → drop/recreate LOCAL → import
php artisan db:sync-production --dump-only                       # just save storage/app/exports/*.sql.gz
php artisan db:sync-production --push-remote --target-connection=staging --force
```

### Mode matrix

| Mode | Flag | Destination | Destructive |
|------|------|-------------|-------------|
| 1. Save dump | `--dump-only` | `storage/app/exports/<db>_<timestamp>.sql.gz` | no |
| 2. Import local | *(default)* | `config('database.default')` | drops local DB |
| 3. Import remote | `--push-remote` | `--target-connection` | drops target DB |

All three share one pipeline: produce one gzipped dump from the source, then branch on mode.

## Source/target access strategy

Each connection declares how it is reached. Read from config (backed by `.env`), keyed per
connection so both source and target get identical treatment.

| Access | Behavior | Fits |
|--------|----------|------|
| `ssh` | run `mysqldump`/`mysql` on the remote host over SSH | Cloudways / Forge |
| `tunnel` | auto-open an SSH tunnel (bastion + remote db host:port → local port), operate through `127.0.0.1:localport`, then tear the tunnel down | mortrack |
| `direct` | local `mysqldump`/`mysql` straight at host:port | LAN / already-open tunnel |

Example `.env` for a tunnel project:

```env
PRODUCTION_ACCESS=tunnel
PRODUCTION_TUNNEL_SSH=bastion-user@ec2-host
PRODUCTION_TUNNEL_REMOTE=db.internal:3306
PRODUCTION_TUNNEL_LOCAL_PORT=13306
```

Example `.env` for a Cloudways/Forge project:

```env
PRODUCTION_ACCESS=ssh
PRODUCTION_SSH_HOST=your-server-ip
PRODUCTION_SSH_USER=forge
PRODUCTION_DB_DATABASE=your_db
PRODUCTION_DB_USERNAME=...
PRODUCTION_DB_PASSWORD=...
```

Every path runs a **preflight reachability check** before dumping and fails with a friendly,
actionable error (e.g. `source unreachable — is the SSH tunnel open / host reachable?`).

## Execution flow

1. **Resolve + validate** the source connection (and target, for `--push-remote`). Fail early
   if a connection is missing, not MySQL, or `--push-remote` is set without `--target-connection`.
2. **Preflight**: for `tunnel`, open the tunnel; for all, verify reachability.
3. **Dump** the source to `storage/app/exports/<db>_<timestamp>.sql.gz` via the source's access
   strategy, streamed through `gzip`. Abort if the dump is empty.
4. **Branch:**
   - `--dump-only`: stop; report the file path.
   - default: import into local.
   - `--push-remote`: import into the target connection.
   - Import = drop/recreate the destination DB, then pipe the gunzipped dump into `mysql`.
5. **Verify**: compare source vs. destination base-table counts; report a summary.
6. **Cleanup**: remove temp option files; close any tunnel; delete the dump unless `--keep-dump`.

## Safety & credentials

- **Credentials via a 0600 `--defaults-extra-file`**, never `-p<password>` on the command line
  (fixes the current file's process-list leak). Temp files removed on completion.
- **`--push-remote` guardrails**: requires `--force`; prints a typed summary of the exact
  host/DB that will be **DROPPED**; refuses when the target host:port equals the source host:port
  (can't overwrite the database you are dumping).
- **`--backup`**: dumps the destination (local or remote) before overwriting it.

## Testing / verification

Template package with no full test harness. Verification is twofold:

- **Manual checklist (README):**
  - `--dump-only` produces a non-empty `.sql.gz`.
  - Local import round-trips: source and local base-table counts match.
  - `--push-remote` without `--force` refuses.
  - The generated option file is `0600`; no password appears in `ps`.
  - `tunnel` access opens the tunnel, dumps, and closes it (no lingering `ssh` process).
- **Optional PHPUnit stub** for the pure helpers: option-file builder, source/target
  endpoint-equality guard, and tunnel-argument builder.

## Files

- `composer.json` (new) — package metadata, PSR-4 autoload, ServiceProvider auto-discovery.
- `src/Mox3UtilsServiceProvider.php` (new) — registers the command.
- `src/Console/Commands/SyncProductionDatabase.php` (moved + reworked) — the command.
- `db-sync/README.md` (updated) — Composer install, `.env` matrix per access mode, verification checklist.
- `db-sync/sync-db.sh` — unchanged; retained for WordPress sites.
- `db-sync/SyncProductionDatabase.php` — removed (superseded by the namespaced class).
