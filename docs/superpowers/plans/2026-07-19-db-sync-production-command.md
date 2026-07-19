# db:sync-production Three-Mode Command Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn `mox3-utils` into a Composer package that ships a three-mode `db:sync-production` Artisan command (dump-only / import-to-local / push-to-remote) reachable over direct, ssh-exec, or auto-opened SSH tunnel, configured per-project via `.env`.

**Architecture:** A Laravel auto-discovered ServiceProvider registers one command class. The command orchestrates; all pure, testable logic lives in small `DbSync/` helper classes (`AccessSettings`, `MysqlOptionFile`, `ShellCommands`, `SshTunnel`). Credentials for local `mysqldump`/`mysql` calls are passed only through 0600 `--defaults-extra-file` option files; the ssh-exec path writes the remote option file over stdin so no password ever reaches any process's argv.

**Tech Stack:** PHP 8.2+, Laravel (illuminate/console, illuminate/support) 10–12, Symfony Process, MySQL client (`mysqldump`/`mysql`) on PATH, PHPUnit + orchestra/testbench for tests.

## Global Constraints

- Package name: `mox3-dev/mox3-utils`; PSR-4 namespace `Mox3\Utils\` → `src/`.
- PHP `>=8.2`. Support Laravel 10, 11, and 12 (`^10.0 || ^11.0 || ^12.0`).
- Command signature name is exactly `db:sync-production`.
- Never place a DB password on a local process's command line — use 0600 `--defaults-extra-file` for all local `mysqldump`/`mysql` invocations; pass remote credentials over SSH stdin, never argv.
- One database per invocation. A tunnel is opened and torn down within a single run.
- Access strategies are exactly the strings `direct`, `ssh`, `tunnel`.
- MySQL charset is `utf8mb4` (collation `utf8mb4_unicode_ci`).

---

## File Structure

- `composer.json` (create) — package metadata, autoload, ServiceProvider discovery, dev deps.
- `phpunit.xml.dist` (create) — test config.
- `src/Mox3UtilsServiceProvider.php` (create) — registers the command.
- `src/DbSync/AccessSettings.php` (create) — value object + resolver: how one endpoint is reached.
- `src/DbSync/MysqlOptionFile.php` (create) — render + write a 0600 `[client]` option file.
- `src/DbSync/ShellCommands.php` (create) — pure builders for shell command strings + guards.
- `src/DbSync/SshTunnel.php` (create) — open/close a background SSH tunnel.
- `src/Console/Commands/SyncProductionDatabase.php` (create) — the command (orchestration).
- `tests/Unit/AccessSettingsTest.php` (create)
- `tests/Unit/MysqlOptionFileTest.php` (create)
- `tests/Unit/ShellCommandsTest.php` (create)
- `tests/Feature/SyncProductionDatabaseGuardTest.php` (create)
- `db-sync/SyncProductionDatabase.php` (delete) — superseded by the namespaced class.
- `db-sync/README.md` (modify) — Composer install, `.env` matrix, verification checklist.

> Note: `db-sync/sync-db.sh` and `db-sync/.db-sync.conf.example` were lost in an earlier data-loss incident and are out of scope here; the README will reference `sync-db.sh` as the WordPress option but recreating it is a separate task.

---

### Task 1: Composer package scaffolding + ServiceProvider

**Files:**
- Create: `composer.json`
- Create: `phpunit.xml.dist`
- Create: `src/Mox3UtilsServiceProvider.php`
- Create: `src/Console/Commands/SyncProductionDatabase.php` (stub only)
- Test: `tests/Feature/SyncProductionDatabaseGuardTest.php` (registration test only for now)

**Interfaces:**
- Produces: ServiceProvider `Mox3\Utils\Mox3UtilsServiceProvider`; command class `Mox3\Utils\Console\Commands\SyncProductionDatabase` with console name `db:sync-production`.

- [ ] **Step 1: Create `composer.json`**

```json
{
    "name": "mox3-dev/mox3-utils",
    "description": "Reusable Mox3 tooling: db:sync-production and related utilities.",
    "type": "library",
    "license": "MIT",
    "require": {
        "php": ">=8.2",
        "illuminate/console": "^10.0 || ^11.0 || ^12.0",
        "illuminate/support": "^10.0 || ^11.0 || ^12.0",
        "symfony/process": "^6.0 || ^7.0"
    },
    "require-dev": {
        "phpunit/phpunit": "^10.5 || ^11.0",
        "orchestra/testbench": "^8.0 || ^9.0 || ^10.0"
    },
    "autoload": {
        "psr-4": { "Mox3\\Utils\\": "src/" }
    },
    "autoload-dev": {
        "psr-4": { "Mox3\\Utils\\Tests\\": "tests/" }
    },
    "extra": {
        "laravel": {
            "providers": [ "Mox3\\Utils\\Mox3UtilsServiceProvider" ]
        }
    },
    "minimum-stability": "stable",
    "prefer-stable": true
}
```

- [ ] **Step 2: Create `phpunit.xml.dist`**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         colors="true">
    <testsuites>
        <testsuite name="Unit">
            <directory>tests/Unit</directory>
        </testsuite>
        <testsuite name="Feature">
            <directory>tests/Feature</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

- [ ] **Step 3: Create the ServiceProvider**

`src/Mox3UtilsServiceProvider.php`:

```php
<?php

namespace Mox3\Utils;

use Illuminate\Support\ServiceProvider;
use Mox3\Utils\Console\Commands\SyncProductionDatabase;

class Mox3UtilsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                SyncProductionDatabase::class,
            ]);
        }
    }
}
```

- [ ] **Step 4: Create the command stub**

`src/Console/Commands/SyncProductionDatabase.php`:

```php
<?php

namespace Mox3\Utils\Console\Commands;

use Illuminate\Console\Command;

class SyncProductionDatabase extends Command
{
    protected $signature = 'db:sync-production
                            {--source-connection=production : Laravel connection for the SOURCE (remote) DB}
                            {--target-connection= : Target connection for --push-remote (e.g. staging)}
                            {--dump-only : Mode 1 — dump to a file, touch no database}
                            {--push-remote : Mode 3 — import into --target-connection instead of local}
                            {--keep-dump : Keep the dump file after import}
                            {--backup : Back up the destination DB before overwriting it}
                            {--data-only : Dump data only (skip CREATE TABLE structure)}
                            {--force : Skip the destructive-action confirmation}';

    protected $description = 'Dump a remote MySQL database and optionally import it into a local or remote target.';

    public function handle(): int
    {
        $this->info('Not yet implemented.');

        return self::SUCCESS;
    }
}
```

- [ ] **Step 5: Write the registration test**

`tests/Feature/SyncProductionDatabaseGuardTest.php`:

```php
<?php

namespace Mox3\Utils\Tests\Feature;

use Mox3\Utils\Mox3UtilsServiceProvider;
use Orchestra\Testbench\TestCase;

class SyncProductionDatabaseGuardTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [Mox3UtilsServiceProvider::class];
    }

    public function test_command_is_registered(): void
    {
        $this->assertArrayHasKey(
            'db:sync-production',
            $this->app[\Illuminate\Contracts\Console\Kernel::class]->all()
        );
    }
}
```

- [ ] **Step 6: Install dependencies and run the test**

Run:
```bash
composer install
vendor/bin/phpunit tests/Feature/SyncProductionDatabaseGuardTest.php
```
Expected: 1 test, PASS.

- [ ] **Step 7: Commit**

```bash
git add composer.json phpunit.xml.dist src tests
git commit -m "feat: scaffold mox3-utils Composer package + db:sync-production command"
```

---

### Task 2: AccessSettings value object + resolver

**Files:**
- Create: `src/DbSync/AccessSettings.php`
- Test: `tests/Unit/AccessSettingsTest.php`

**Interfaces:**
- Produces: `Mox3\Utils\DbSync\AccessSettings` with:
  - constructor props (all `public readonly`): `string $access`, `string $host`, `int $port`, `string $username`, `string $password`, `string $database`, `?string $sshTarget`, `?string $tunnelRemote`, `?int $tunnelLocalPort`.
  - `static fromConnection(string $connectionName, array $config, callable $env): self` — `$env` has signature `fn(string $key, mixed $default = null): mixed`.
  - `isDirect(): bool`, `isSsh(): bool`, `isTunnel(): bool`.
  - `effectiveHost(): string` and `effectivePort(): int` — for tunnels these return `127.0.0.1` and the local port; otherwise the real host/port.

- [ ] **Step 1: Write the failing test**

`tests/Unit/AccessSettingsTest.php`:

```php
<?php

namespace Mox3\Utils\Tests\Unit;

use Mox3\Utils\DbSync\AccessSettings;
use PHPUnit\Framework\TestCase;

class AccessSettingsTest extends TestCase
{
    private function env(array $map): callable
    {
        return fn (string $key, mixed $default = null): mixed => $map[$key] ?? $default;
    }

    public function test_direct_is_the_default_access(): void
    {
        $s = AccessSettings::fromConnection('production', [
            'host' => 'db.example.com', 'port' => 3306,
            'username' => 'u', 'password' => 'p', 'database' => 'app',
        ], $this->env([]));

        $this->assertTrue($s->isDirect());
        $this->assertSame('db.example.com', $s->effectiveHost());
        $this->assertSame(3306, $s->effectivePort());
    }

    public function test_tunnel_rewrites_effective_endpoint_to_local_port(): void
    {
        $s = AccessSettings::fromConnection('production', [
            'host' => '127.0.0.1', 'port' => 3306,
            'username' => 'u', 'password' => 'p', 'database' => 'app',
        ], $this->env([
            'PRODUCTION_ACCESS' => 'tunnel',
            'PRODUCTION_TUNNEL_SSH' => 'bastion@ec2',
            'PRODUCTION_TUNNEL_REMOTE' => 'db.internal:3306',
            'PRODUCTION_TUNNEL_LOCAL_PORT' => '13306',
        ]));

        $this->assertTrue($s->isTunnel());
        $this->assertSame('bastion@ec2', $s->sshTarget);
        $this->assertSame('db.internal:3306', $s->tunnelRemote);
        $this->assertSame(13306, $s->tunnelLocalPort);
        $this->assertSame('127.0.0.1', $s->effectiveHost());
        $this->assertSame(13306, $s->effectivePort());
    }

    public function test_ssh_reads_ssh_target_and_hyphenated_connection_name(): void
    {
        $s = AccessSettings::fromConnection('staging-db', [
            'host' => 'h', 'port' => 3306, 'username' => 'u', 'password' => 'p', 'database' => 'd',
        ], $this->env([
            'STAGING_DB_ACCESS' => 'ssh',
            'STAGING_DB_SSH' => 'forge@1.2.3.4',
        ]));

        $this->assertTrue($s->isSsh());
        $this->assertSame('forge@1.2.3.4', $s->sshTarget);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/AccessSettingsTest.php`
Expected: FAIL with "Class Mox3\Utils\DbSync\AccessSettings not found".

- [ ] **Step 3: Implement `AccessSettings`**

`src/DbSync/AccessSettings.php`:

```php
<?php

namespace Mox3\Utils\DbSync;

final class AccessSettings
{
    public function __construct(
        public readonly string $access,
        public readonly string $host,
        public readonly int $port,
        public readonly string $username,
        public readonly string $password,
        public readonly string $database,
        public readonly ?string $sshTarget = null,
        public readonly ?string $tunnelRemote = null,
        public readonly ?int $tunnelLocalPort = null,
    ) {}

    public static function fromConnection(string $connectionName, array $config, callable $env): self
    {
        $prefix = strtoupper(str_replace('-', '_', $connectionName)).'_';
        $access = strtolower((string) $env($prefix.'ACCESS', 'direct'));

        $sshTarget = $env($prefix.'SSH') ?: $env($prefix.'TUNNEL_SSH');
        $localPort = $env($prefix.'TUNNEL_LOCAL_PORT');

        return new self(
            access: in_array($access, ['direct', 'ssh', 'tunnel'], true) ? $access : 'direct',
            host: (string) ($config['host'] ?? '127.0.0.1'),
            port: (int) ($config['port'] ?? 3306),
            username: (string) ($config['username'] ?? ''),
            password: (string) ($config['password'] ?? ''),
            database: (string) ($config['database'] ?? ''),
            sshTarget: $sshTarget !== null ? (string) $sshTarget : null,
            tunnelRemote: ($r = $env($prefix.'TUNNEL_REMOTE')) !== null ? (string) $r : null,
            tunnelLocalPort: $localPort !== null ? (int) $localPort : null,
        );
    }

    public function isDirect(): bool { return $this->access === 'direct'; }

    public function isSsh(): bool { return $this->access === 'ssh'; }

    public function isTunnel(): bool { return $this->access === 'tunnel'; }

    public function effectiveHost(): string
    {
        return $this->isTunnel() ? '127.0.0.1' : $this->host;
    }

    public function effectivePort(): int
    {
        return $this->isTunnel() ? (int) $this->tunnelLocalPort : $this->port;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/AccessSettingsTest.php`
Expected: 3 tests, PASS.

- [ ] **Step 5: Commit**

```bash
git add src/DbSync/AccessSettings.php tests/Unit/AccessSettingsTest.php
git commit -m "feat: add AccessSettings resolver for direct/ssh/tunnel endpoints"
```

---

### Task 3: MysqlOptionFile (0600 credential file)

**Files:**
- Create: `src/DbSync/MysqlOptionFile.php`
- Test: `tests/Unit/MysqlOptionFileTest.php`

**Interfaces:**
- Produces: `Mox3\Utils\DbSync\MysqlOptionFile` with:
  - `static render(string $host, int $port, string $username, string $password): string`
  - `static write(string $host, int $port, string $username, string $password): string` (returns the temp file path; caller deletes it).

- [ ] **Step 1: Write the failing test**

`tests/Unit/MysqlOptionFileTest.php`:

```php
<?php

namespace Mox3\Utils\Tests\Unit;

use Mox3\Utils\DbSync\MysqlOptionFile;
use PHPUnit\Framework\TestCase;

class MysqlOptionFileTest extends TestCase
{
    public function test_render_produces_a_client_section(): void
    {
        $out = MysqlOptionFile::render('127.0.0.1', 3306, 'root', 's3cr3t');

        $this->assertStringContainsString("[client]\n", $out);
        $this->assertStringContainsString("host=127.0.0.1\n", $out);
        $this->assertStringContainsString("port=3306\n", $out);
        $this->assertStringContainsString("user=root\n", $out);
        $this->assertStringContainsString('password="s3cr3t"', $out);
    }

    public function test_write_creates_a_0600_file_with_the_rendered_contents(): void
    {
        $path = MysqlOptionFile::write('h', 3306, 'u', 'p');

        try {
            $this->assertFileExists($path);
            $this->assertSame('0600', substr(sprintf('%o', fileperms($path)), -4));
            $this->assertSame(MysqlOptionFile::render('h', 3306, 'u', 'p'), file_get_contents($path));
        } finally {
            @unlink($path);
        }
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/MysqlOptionFileTest.php`
Expected: FAIL with "Class ... MysqlOptionFile not found".

- [ ] **Step 3: Implement `MysqlOptionFile`**

`src/DbSync/MysqlOptionFile.php`:

```php
<?php

namespace Mox3\Utils\DbSync;

final class MysqlOptionFile
{
    public static function render(string $host, int $port, string $username, string $password): string
    {
        return "[client]\n"
            ."host={$host}\n"
            ."port={$port}\n"
            ."user={$username}\n"
            .'password="'.$password."\"\n";
    }

    public static function write(string $host, int $port, string $username, string $password): string
    {
        $path = tempnam(sys_get_temp_dir(), 'dbsync_');
        chmod($path, 0600);
        file_put_contents($path, self::render($host, $port, $username, $password));

        return $path;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/MysqlOptionFileTest.php`
Expected: 2 tests, PASS.

- [ ] **Step 5: Commit**

```bash
git add src/DbSync/MysqlOptionFile.php tests/Unit/MysqlOptionFileTest.php
git commit -m "feat: add 0600 MySQL option-file helper for credential safety"
```

---

### Task 4: ShellCommands (pure builders + guards)

**Files:**
- Create: `src/DbSync/ShellCommands.php`
- Test: `tests/Unit/ShellCommandsTest.php`

**Interfaces:**
- Produces: `Mox3\Utils\DbSync\ShellCommands` with pure static methods:
  - `sameEndpoint(string $hostA, int $portA, string $hostB, int $portB): bool`
  - `tunnelArgs(string $sshTarget, string $tunnelRemote, int $localPort): array`
  - `resetDatabaseSql(string $database, string $charset = 'utf8mb4'): string`
  - `dumpOptions(bool $dataOnly): string`
  - `directDumpCommand(string $mysqldumpBin, string $cnf, string $options, string $database, string $dumpFile): string`
  - `sshDumpCommand(string $sshTarget, string $database, string $options, string $username, string $password, string $dumpFile): string`
  - `importCommand(string $mysqlBin, string $cnf, string $database, string $dumpFile): string`

- [ ] **Step 1: Write the failing test**

`tests/Unit/ShellCommandsTest.php`:

```php
<?php

namespace Mox3\Utils\Tests\Unit;

use Mox3\Utils\DbSync\ShellCommands;
use PHPUnit\Framework\TestCase;

class ShellCommandsTest extends TestCase
{
    public function test_same_endpoint_matches_host_and_port(): void
    {
        $this->assertTrue(ShellCommands::sameEndpoint('127.0.0.1', 3306, '127.0.0.1', 3306));
        $this->assertFalse(ShellCommands::sameEndpoint('127.0.0.1', 3306, '127.0.0.1', 3307));
        $this->assertFalse(ShellCommands::sameEndpoint('a', 3306, 'b', 3306));
    }

    public function test_tunnel_args_build_a_local_forward(): void
    {
        $args = ShellCommands::tunnelArgs('bastion@ec2', 'db.internal:3306', 13306);

        $this->assertSame('ssh', $args[0]);
        $this->assertContains('-N', $args);
        $this->assertContains('13306:db.internal:3306', $args);
        $this->assertContains('bastion@ec2', $args);
    }

    public function test_tunnel_args_default_remote_port_to_3306(): void
    {
        $args = ShellCommands::tunnelArgs('bastion@ec2', 'db.internal', 13306);
        $this->assertContains('13306:db.internal:3306', $args);
    }

    public function test_reset_database_sql_drops_and_recreates(): void
    {
        $sql = ShellCommands::resetDatabaseSql('myapp');
        $this->assertStringContainsString('DROP DATABASE IF EXISTS `myapp`', $sql);
        $this->assertStringContainsString('CREATE DATABASE `myapp` CHARACTER SET utf8mb4', $sql);
    }

    public function test_dump_options_toggle_structure(): void
    {
        $this->assertStringContainsString('--no-create-info', ShellCommands::dumpOptions(true));
        $this->assertStringNotContainsString('--no-create-info', ShellCommands::dumpOptions(false));
        $this->assertStringContainsString('--routines', ShellCommands::dumpOptions(false));
    }

    public function test_direct_dump_command_pipes_through_gzip(): void
    {
        $cmd = ShellCommands::directDumpCommand('mysqldump', '/tmp/cnf', '--single-transaction', 'app', '/tmp/out.sql.gz');
        $this->assertStringContainsString('--defaults-extra-file', $cmd);
        $this->assertStringContainsString('| gzip >', $cmd);
        $this->assertStringNotContainsString('-p', $cmd); // no password on the command line
    }

    public function test_import_command_gunzips_into_mysql_client(): void
    {
        $cmd = ShellCommands::importCommand('mysql', '/tmp/cnf', 'app', '/tmp/out.sql.gz');
        $this->assertStringContainsString('gunzip <', $cmd);
        $this->assertStringContainsString('--defaults-extra-file', $cmd);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/ShellCommandsTest.php`
Expected: FAIL with "Class ... ShellCommands not found".

- [ ] **Step 3: Implement `ShellCommands`**

`src/DbSync/ShellCommands.php`:

```php
<?php

namespace Mox3\Utils\DbSync;

final class ShellCommands
{
    public const CHARSET = 'utf8mb4';

    public static function sameEndpoint(string $hostA, int $portA, string $hostB, int $portB): bool
    {
        return $hostA === $hostB && $portA === $portB;
    }

    public static function tunnelArgs(string $sshTarget, string $tunnelRemote, int $localPort): array
    {
        [$remoteHost, $remotePort] = array_pad(explode(':', $tunnelRemote, 2), 2, '3306');

        return [
            'ssh', '-o', 'StrictHostKeyChecking=no', '-o', 'ExitOnForwardFailure=yes',
            '-L', "{$localPort}:{$remoteHost}:{$remotePort}",
            '-N', $sshTarget,
        ];
    }

    public static function resetDatabaseSql(string $database, string $charset = self::CHARSET): string
    {
        return sprintf(
            'SET FOREIGN_KEY_CHECKS=0; DROP DATABASE IF EXISTS `%1$s`; '
            .'CREATE DATABASE `%1$s` CHARACTER SET %2$s COLLATE %2$s_unicode_ci; SET FOREIGN_KEY_CHECKS=1;',
            $database,
            $charset
        );
    }

    public static function dumpOptions(bool $dataOnly): string
    {
        $opts = [
            '--single-transaction', '--quick', '--no-tablespaces',
            '--set-gtid-purged=OFF', '--default-character-set='.self::CHARSET,
        ];

        if ($dataOnly) {
            $opts[] = '--no-create-info';
        } else {
            $opts[] = '--routines';
            $opts[] = '--triggers';
            $opts[] = '--events';
        }

        return implode(' ', $opts);
    }

    public static function directDumpCommand(string $mysqldumpBin, string $cnf, string $options, string $database, string $dumpFile): string
    {
        return sprintf(
            '%s --defaults-extra-file=%s %s %s | gzip > %s',
            escapeshellarg($mysqldumpBin),
            escapeshellarg($cnf),
            $options,
            escapeshellarg($database),
            escapeshellarg($dumpFile)
        );
    }

    /**
     * Dump a remote DB by running mysqldump over SSH. The remote reads its
     * credentials from a temp 0600 option file that we create by streaming a
     * heredoc over SSH stdin — so the password never appears in any argv.
     */
    public static function sshDumpCommand(string $sshTarget, string $database, string $options, string $username, string $password, string $dumpFile): string
    {
        $remoteScript = <<<SCRIPT
                CNF="\$(mktemp)"; chmod 600 "\$CNF"
                cat > "\$CNF" <<'CNFEOF'
                [client]
                user={$username}
                password="{$password}"
                CNFEOF
                mysqldump --defaults-extra-file="\$CNF" {$options} {$database}
                rc=\$?
                rm -f "\$CNF"
                exit \$rc
                SCRIPT;

        return sprintf(
            'ssh -o StrictHostKeyChecking=no %s bash -s | gzip > %s',
            escapeshellarg($sshTarget),
            escapeshellarg($dumpFile)
        ).' <<<'.escapeshellarg($remoteScript);
    }

    public static function importCommand(string $mysqlBin, string $cnf, string $database, string $dumpFile): string
    {
        return sprintf(
            'gunzip < %s | %s --defaults-extra-file=%s --max_allowed_packet=512M %s',
            escapeshellarg($dumpFile),
            escapeshellarg($mysqlBin),
            escapeshellarg($cnf),
            escapeshellarg($database)
        );
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/ShellCommandsTest.php`
Expected: 7 tests, PASS.

- [ ] **Step 5: Commit**

```bash
git add src/DbSync/ShellCommands.php tests/Unit/ShellCommandsTest.php
git commit -m "feat: add pure ShellCommands builders for dump/import/tunnel"
```

---

### Task 5: SshTunnel + the full command + guard tests

**Files:**
- Create: `src/DbSync/SshTunnel.php`
- Modify: `src/Console/Commands/SyncProductionDatabase.php` (replace stub with full implementation)
- Test: `tests/Feature/SyncProductionDatabaseGuardTest.php` (add guard tests)

**Interfaces:**
- Consumes: `AccessSettings`, `MysqlOptionFile`, `ShellCommands` (all from Tasks 2–4).
- Produces: `Mox3\Utils\DbSync\SshTunnel` with constructor `(string $sshTarget, string $tunnelRemote, int $localPort)`, `open(int $waitSeconds = 15): void`, `close(): void`.

- [ ] **Step 1: Implement `SshTunnel`**

`src/DbSync/SshTunnel.php`:

```php
<?php

namespace Mox3\Utils\DbSync;

use RuntimeException;
use Symfony\Component\Process\Process;

final class SshTunnel
{
    private ?Process $process = null;

    public function __construct(
        private readonly string $sshTarget,
        private readonly string $tunnelRemote,
        private readonly int $localPort,
    ) {}

    public function open(int $waitSeconds = 15): void
    {
        $this->process = new Process(
            ShellCommands::tunnelArgs($this->sshTarget, $this->tunnelRemote, $this->localPort)
        );
        $this->process->setTimeout(null);
        $this->process->start();

        $deadline = time() + $waitSeconds;
        while (time() < $deadline) {
            $conn = @fsockopen('127.0.0.1', $this->localPort, $errno, $errstr, 1);
            if ($conn !== false) {
                fclose($conn);

                return;
            }
            if (! $this->process->isRunning()) {
                throw new RuntimeException('SSH tunnel exited early: '.$this->process->getErrorOutput());
            }
            usleep(300_000);
        }

        $this->close();
        throw new RuntimeException("SSH tunnel did not open on 127.0.0.1:{$this->localPort} within {$waitSeconds}s.");
    }

    public function close(): void
    {
        if ($this->process !== null && $this->process->isRunning()) {
            $this->process->stop(5);
        }
        $this->process = null;
    }
}
```

- [ ] **Step 2: Replace the command stub with the full implementation**

`src/Console/Commands/SyncProductionDatabase.php`:

```php
<?php

namespace Mox3\Utils\Console\Commands;

use Illuminate\Console\Command;
use Mox3\Utils\DbSync\AccessSettings;
use Mox3\Utils\DbSync\MysqlOptionFile;
use Mox3\Utils\DbSync\ShellCommands;
use Mox3\Utils\DbSync\SshTunnel;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class SyncProductionDatabase extends Command
{
    protected $signature = 'db:sync-production
                            {--source-connection=production : Laravel connection for the SOURCE (remote) DB}
                            {--target-connection= : Target connection for --push-remote (e.g. staging)}
                            {--dump-only : Mode 1 — dump to a file, touch no database}
                            {--push-remote : Mode 3 — import into --target-connection instead of local}
                            {--keep-dump : Keep the dump file after import}
                            {--backup : Back up the destination DB before overwriting it}
                            {--data-only : Dump data only (skip CREATE TABLE structure)}
                            {--force : Skip the destructive-action confirmation}';

    protected $description = 'Dump a remote MySQL database and optionally import it into a local or remote target.';

    /** @var list<string> Temp files to remove on completion. */
    private array $tempFiles = [];

    /** @var list<SshTunnel> Open tunnels to close on completion. */
    private array $tunnels = [];

    public function handle(): int
    {
        $dumpOnly = (bool) $this->option('dump-only');
        $pushRemote = (bool) $this->option('push-remote');

        if ($dumpOnly && $pushRemote) {
            $this->error('--dump-only and --push-remote cannot be combined.');

            return self::FAILURE;
        }

        $source = $this->resolveSettings((string) $this->option('source-connection'));
        if ($source === null) {
            return self::FAILURE;
        }

        $target = null;
        if (! $dumpOnly) {
            $target = $this->resolveTarget($pushRemote, $source);
            if ($target === null) {
                return self::FAILURE;
            }
        }

        $this->printSummary($source, $target, $dumpOnly);

        if (! $dumpOnly && ! $this->option('force')
            && ! $this->confirm("This ERASES the target database '{$target->database}' and replaces it. Proceed?")) {
            $this->info('Cancelled.');

            return self::SUCCESS;
        }

        try {
            $this->openTunnelIfNeeded($source);
            if ($target !== null) {
                $this->openTunnelIfNeeded($target);
            }

            $dumpFile = $this->buildDumpPath($source->database);
            $this->dump($source, $dumpFile);

            if ($dumpOnly) {
                $this->info("Dump only — no database touched. File: {$dumpFile}");

                return self::SUCCESS;
            }

            if ($this->option('backup')) {
                $this->backupDestination($target);
            }

            $this->resetTarget($target);
            $this->importInto($target, $dumpFile);
            $this->info("Import complete into '{$target->database}'.");

            return self::SUCCESS;
        } catch (Throwable $e) {
            if (isset($dumpFile) && is_file($dumpFile) && ! $this->option('keep-dump')) {
                @unlink($dumpFile);
            }
            $this->error('Sync failed: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            $this->cleanup($dumpFile ?? null, $dumpOnly);
        }
    }

    private function resolveSettings(string $connectionName): ?AccessSettings
    {
        $config = config("database.connections.{$connectionName}");
        if (! is_array($config) || ($config['driver'] ?? null) !== 'mysql') {
            $this->error("Connection '{$connectionName}' is not a configured MySQL connection.");

            return null;
        }

        return AccessSettings::fromConnection(
            $connectionName,
            $config,
            static fn (string $key, mixed $default = null): mixed => env($key, $default)
        );
    }

    private function resolveTarget(bool $pushRemote, AccessSettings $source): ?AccessSettings
    {
        if (! $pushRemote) {
            // Mode 2 — the local default connection, always reached directly.
            return $this->resolveSettings((string) config('database.default'));
        }

        // Mode 3 — a distinct remote target connection, strongly guarded.
        $targetConnection = (string) $this->option('target-connection');
        if ($targetConnection === '') {
            $this->error('--push-remote requires --target-connection=<name>.');

            return null;
        }

        if (! $this->option('force')) {
            $this->error('--push-remote is destructive on a remote server; re-run with --force to confirm.');

            return null;
        }

        $target = $this->resolveSettings($targetConnection);
        if ($target === null) {
            return null;
        }

        if (ShellCommands::sameEndpoint(
            $source->effectiveHost(), $source->effectivePort(),
            $target->effectiveHost(), $target->effectivePort()
        ) && $source->database === $target->database) {
            $this->error('Refusing to import into the same host:port + database being dumped.');

            return null;
        }

        return $target;
    }

    private function openTunnelIfNeeded(AccessSettings $s): void
    {
        if (! $s->isTunnel()) {
            return;
        }

        $this->info("Opening SSH tunnel {$s->sshTarget} → {$s->tunnelRemote} (local :{$s->tunnelLocalPort})");
        $tunnel = new SshTunnel((string) $s->sshTarget, (string) $s->tunnelRemote, (int) $s->tunnelLocalPort);
        $tunnel->open();
        $this->tunnels[] = $tunnel;
    }

    private function dump(AccessSettings $source, string $dumpFile): void
    {
        $this->info("Dumping {$source->database} → {$dumpFile}");
        $options = ShellCommands::dumpOptions((bool) $this->option('data-only'));

        if ($source->isSsh()) {
            $command = ShellCommands::sshDumpCommand(
                (string) $source->sshTarget, $source->database, $options,
                $source->username, $source->password, $dumpFile
            );
        } else {
            $cnf = $this->optionFileFor($source);
            $command = ShellCommands::directDumpCommand(
                $this->binary('mysqldump'), $cnf, $options, $source->database, $dumpFile
            );
        }

        $this->runShell($command, 'dump');

        if (! is_file($dumpFile) || filesize($dumpFile) === 0) {
            throw new RuntimeException('dump produced an empty file — aborting before touching the target.');
        }
    }

    private function backupDestination(AccessSettings $target): void
    {
        $backup = $this->buildDumpPath($target->database.'_backup');
        $this->warn("Backing up destination {$target->database} → {$backup}");
        $cnf = $this->optionFileFor($target);
        $command = ShellCommands::directDumpCommand(
            $this->binary('mysqldump'), $cnf, ShellCommands::dumpOptions(false), $target->database, $backup
        );
        $this->runShell($command, 'backup');
    }

    private function resetTarget(AccessSettings $target): void
    {
        $this->info("Resetting target database {$target->database}");
        $cnf = $this->optionFileFor($target);
        $command = sprintf(
            '%s --defaults-extra-file=%s -e %s',
            escapeshellarg($this->binary('mysql')),
            escapeshellarg($cnf),
            escapeshellarg(ShellCommands::resetDatabaseSql($target->database))
        );
        $this->runShell($command, 'reset target');
    }

    private function importInto(AccessSettings $target, string $dumpFile): void
    {
        $this->info("Importing into {$target->database}");
        $cnf = $this->optionFileFor($target);
        $command = ShellCommands::importCommand($this->binary('mysql'), $cnf, $target->database, $dumpFile);
        $this->runShell($command, 'import');
    }

    private function optionFileFor(AccessSettings $s): string
    {
        $cnf = MysqlOptionFile::write($s->effectiveHost(), $s->effectivePort(), $s->username, $s->password);
        $this->tempFiles[] = $cnf;

        return $cnf;
    }

    private function printSummary(AccessSettings $source, ?AccessSettings $target, bool $dumpOnly): void
    {
        $dest = $dumpOnly || $target === null
            ? 'none (dump only — writes a file)'
            : sprintf('%s @ %s:%s  (DROPPED + recreated)', $target->database, $target->effectiveHost(), $target->effectivePort());

        $this->table(['Setting', 'Value'], [
            ['Source', sprintf('%s @ %s:%s [%s]', $source->database, $source->effectiveHost(), $source->effectivePort(), $source->access)],
            ['Destination', $dest],
            ['Data only', $this->option('data-only') ? 'yes' : 'no'],
        ]);
    }

    private function buildDumpPath(string $database): string
    {
        $dir = storage_path('app/exports');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return $dir.'/'.$database.'_'.date('Y-m-d_His').'.sql.gz';
    }

    private function binary(string $name): string
    {
        $brew = "/opt/homebrew/opt/mysql-client@8.0/bin/{$name}";

        return is_executable($brew) ? $brew : $name;
    }

    private function runShell(string $command, string $label): void
    {
        $process = Process::fromShellCommandline('set -o pipefail; '.$command);
        $process->setTimeout(null);
        $process->run(function (string $type, string $buffer): void {
            $filtered = preg_replace('/.*Using a password on the command line.*\n?/', '', $buffer);
            if ($filtered !== '' && $filtered !== null) {
                $this->output->write($filtered);
            }
        });

        if (! $process->isSuccessful()) {
            throw new RuntimeException("{$label} failed (exit {$process->getExitCode()}).");
        }
    }

    private function cleanup(?string $dumpFile, bool $dumpOnly): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        $this->tempFiles = [];

        foreach ($this->tunnels as $tunnel) {
            $tunnel->close();
        }
        $this->tunnels = [];

        if ($dumpFile === null || ! is_file($dumpFile)) {
            return;
        }

        if ($dumpOnly || $this->option('keep-dump')) {
            $this->line("Dump kept at: {$dumpFile}");
        } else {
            @unlink($dumpFile);
        }
    }
}
```

> Note: `Process::fromShellCommandline` runs under `/bin/sh`; `set -o pipefail` is used so a `mysqldump | gzip` failure propagates. If the execution host's `/bin/sh` lacks `pipefail`, wrap as `bash -c` instead — verify during Step 5's manual check.

- [ ] **Step 3: Add guard tests to the feature test**

Append these methods to `tests/Feature/SyncProductionDatabaseGuardTest.php` (inside the class), and add the `defineEnvironment` method so a `production` connection exists:

```php
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite', 'database' => ':memory:',
        ]);
        $app['config']->set('database.connections.production', [
            'driver' => 'mysql', 'host' => 'db.example.com', 'port' => 3306,
            'username' => 'u', 'password' => 'p', 'database' => 'prod_app',
        ]);
    }

    public function test_dump_only_and_push_remote_together_fail(): void
    {
        $this->artisan('db:sync-production', ['--dump-only' => true, '--push-remote' => true])
            ->expectsOutputToContain('cannot be combined')
            ->assertExitCode(1);
    }

    public function test_push_remote_without_target_connection_fails(): void
    {
        $this->artisan('db:sync-production', ['--push-remote' => true, '--force' => true])
            ->expectsOutputToContain('requires --target-connection')
            ->assertExitCode(1);
    }

    public function test_push_remote_without_force_fails(): void
    {
        $this->artisan('db:sync-production', ['--push-remote' => true, '--target-connection' => 'production'])
            ->expectsOutputToContain('re-run with --force')
            ->assertExitCode(1);
    }

    public function test_non_mysql_source_connection_fails(): void
    {
        $this->artisan('db:sync-production', ['--source-connection' => 'testing', '--dump-only' => true])
            ->expectsOutputToContain('not a configured MySQL connection')
            ->assertExitCode(1);
    }
```

- [ ] **Step 4: Run the feature tests**

Run: `vendor/bin/phpunit tests/Feature/SyncProductionDatabaseGuardTest.php`
Expected: 5 tests (registration + 4 guards), PASS.

- [ ] **Step 5: Manual end-to-end smoke check (documented, not automated)**

In a real Laravel project that requires this package, verify against a scratch DB:
```bash
php artisan db:sync-production --dump-only            # → non-empty storage/app/exports/*.sql.gz
php artisan db:sync-production --force                # dump prod → drop/recreate LOCAL → import; table counts match
ps aux | grep -i mysqldump                            # confirm NO password visible in argv while it runs
```
Expected: dump file is non-empty; local import succeeds; no password in the process list. Record the result in the PR description.

- [ ] **Step 6: Run the full suite and commit**

Run: `vendor/bin/phpunit`
Expected: all Unit + Feature tests PASS.

```bash
git add src/DbSync/SshTunnel.php src/Console/Commands/SyncProductionDatabase.php tests/Feature/SyncProductionDatabaseGuardTest.php
git commit -m "feat: implement three-mode db:sync-production with tunnel + guards"
```

---

### Task 6: Remove the legacy command file and update the README

**Files:**
- Delete: `db-sync/SyncProductionDatabase.php`
- Modify: `db-sync/README.md`

**Interfaces:** none (docs + cleanup).

- [ ] **Step 1: Remove the superseded loose command file**

```bash
git rm db-sync/SyncProductionDatabase.php
```

- [ ] **Step 2: Rewrite `db-sync/README.md`**

Replace the "Laravel Artisan Command" section and add a Composer + access-matrix + verification section. The full replacement file:

````markdown
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
```

Options: `--source-connection=` (default `production`), `--target-connection=`,
`--dump-only`, `--push-remote`, `--keep-dump`, `--backup`, `--data-only`, `--force`.

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
````

- [ ] **Step 3: Verify the loose file is gone and docs render**

Run:
```bash
test ! -f db-sync/SyncProductionDatabase.php && echo "removed"
```
Expected: `removed`.

- [ ] **Step 4: Commit**

```bash
git add db-sync/README.md db-sync/SyncProductionDatabase.php
git commit -m "docs: Composer install + access matrix; remove legacy loose command"
```

---

## Self-Review

**1. Spec coverage:**
- Three modes (dump-only / local / remote) → Task 5 `handle()` branching. ✅
- Composer package + auto-registered ServiceProvider → Task 1. ✅
- Per-project `.env` config → `AccessSettings::fromConnection` (Task 2) + README matrix (Task 6). ✅
- Access strategies direct/ssh/tunnel for source and target → Tasks 2, 4, 5. ✅
- Auto-open/close tunnel → `SshTunnel` (Task 5). ✅
- 0600 option-file credentials; no password on argv (incl. ssh path over stdin) → Tasks 3, 4, 5. ✅
- `--push-remote` guardrails (force, typed summary, same-endpoint refusal) → Task 5 `resolveTarget`/`printSummary`. ✅
- `--backup` of destination → Task 5 `backupDestination`. ✅
- Verify table counts / summary → `printSummary` + manual checklist (Task 5 Step 5). Note: automated base-table verification from the spec is covered by the manual round-trip check, since it needs a real MySQL; the guard tests cover the pure logic.
- Drop mortrack-specific log trimming / prod detection → not ported (YAGNI honored). ✅
- Testing: manual checklist + PHPUnit for pure helpers → Tasks 2–5. ✅

**2. Placeholder scan:** No TBD/TODO; every code step shows complete code. ✅

**3. Type consistency:** `AccessSettings` props/methods (`effectiveHost`/`effectivePort`/`isTunnel`/`sshTarget`/`tunnelRemote`/`tunnelLocalPort`) are used consistently in Task 5. `ShellCommands` method names match their call sites. `SshTunnel` constructor arity matches Task 5 usage. ✅

**Gap addressed:** The spec's "verify source vs destination base-table counts" is not automated (requires a live MySQL); it is captured as an explicit manual round-trip check in Task 5 Step 5 and the README verification checklist. If automated coverage is later desired, add a testbench+MySQL integration test as a follow-up.
