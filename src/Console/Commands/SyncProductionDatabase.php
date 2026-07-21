<?php

namespace Mox3\Utils\Console\Commands;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Mox3\Utils\DbSync\AccessSettings;
use Mox3\Utils\DbSync\DatabaseTargets;
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
                            {--strip-foreign-keys : Drop FK constraints during import (for MySQL 8.4+/9.x targets that reject legacy FKs referencing non-unique columns)}
                            {--databases=* : Sync multiple databases (repeatable or comma-separated). Each item is a DB name, or source:target to rename the target. Overrides the connection database.}
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

        try {
            $pairs = $this->databasePairs($source, $target);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->printSummary($source, $target, $dumpOnly, $pairs);

        if (! $dumpOnly && ! $this->option('force')) {
            $targets = implode(', ', array_map(static fn (array $p): string => "'{$p[1]}'", $pairs));
            if (! $this->confirm("This ERASES the target database(s) {$targets} and replaces them. Proceed?")) {
                $this->info('Cancelled.');

                return self::SUCCESS;
            }
        }

        // Tunnels depend only on the endpoint, not the database, so open once.
        try {
            $this->openTunnelIfNeeded($source);
            if ($target !== null) {
                $this->openTunnelIfNeeded($target);
            }

            $results = [];
            $anyFailed = false;

            foreach ($pairs as [$srcDb, $tgtDb]) {
                $jobSource = $source->withDatabase($srcDb);
                $jobTarget = $target?->withDatabase($tgtDb);

                try {
                    $this->guardNotSelfOverwrite($jobSource, $jobTarget);
                    $this->syncOne($jobSource, $jobTarget, $dumpOnly);
                    $results[] = [$srcDb, $tgtDb, 'ok'];
                } catch (Throwable $e) {
                    $anyFailed = true;
                    $this->error("[{$srcDb}] failed: ".$e->getMessage());
                    $results[] = [$srcDb, $tgtDb, 'FAILED — '.$e->getMessage()];
                }
            }

            if (count($pairs) > 1 || $anyFailed) {
                $this->printResults($results, $dumpOnly);
            }

            return $anyFailed ? self::FAILURE : self::SUCCESS;
        } finally {
            $this->closeInfra();
        }
    }

    /**
     * Resolve the ordered [sourceDb, targetDb] jobs. With --databases, each
     * entry overrides both sides (target defaults to the source name). Without
     * it, a single job derived from each connection's configured database —
     * exactly the pre-existing single-DB behavior.
     *
     * @return list<array{0:string, 1:string}>
     */
    private function databasePairs(AccessSettings $source, ?AccessSettings $target): array
    {
        $pairs = DatabaseTargets::parse((array) $this->option('databases'));
        if ($pairs !== []) {
            return $pairs;
        }

        return [[$source->database, $target?->database ?? $source->database]];
    }

    /** Refuse to import into the exact physical database being dumped. */
    private function guardNotSelfOverwrite(AccessSettings $source, ?AccessSettings $target): void
    {
        if ($target !== null
            && $source->identity() === $target->identity()
            && $source->database === $target->database) {
            throw new RuntimeException(
                "refusing to overwrite '{$source->database}' with itself (source and target resolve to the same endpoint)."
            );
        }
    }

    /** Full dump → (backup) → reset → import lifecycle for one database. */
    private function syncOne(AccessSettings $source, ?AccessSettings $target, bool $dumpOnly): void
    {
        $dumpFile = null;

        try {
            $dumpFile = $this->buildDumpPath($source->database);
            $this->dump($source, $dumpFile);

            if ($dumpOnly) {
                $this->info("Dump only — no database touched. File: {$dumpFile}");

                return;
            }

            if ($this->option('backup')) {
                $this->backupDestination($target);
            }

            $this->resetTarget($target);
            $this->importInto($target, $dumpFile);
            $this->info("Import complete into '{$target->database}'.");
        } finally {
            if ($dumpFile !== null && is_file($dumpFile)) {
                if ($dumpOnly || $this->option('keep-dump')) {
                    $this->line("Dump kept at: {$dumpFile}");
                } else {
                    @unlink($dumpFile);
                }
            }
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

        if ($target->isSsh()) {
            $this->error("--push-remote does not support an 'ssh'-access target ('{$targetConnection}'): a secure remote import cannot keep credentials off the command line over ssh-exec. Use 'tunnel' access to reach the target's database (or 'direct' if it is directly reachable).");

            return null;
        }

        if ($source->identity() === $target->identity() && $source->database === $target->database) {
            $this->error('Refusing to import into the same physical database being dumped (source and target resolve to the same endpoint).');

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
        $command = ShellCommands::importCommand(
            $this->binary('mysql'),
            $cnf,
            $target->database,
            $dumpFile,
            (bool) $this->option('strip-foreign-keys')
        );
        $this->runShell($command, 'import');
    }

    private function optionFileFor(AccessSettings $s): string
    {
        $cnf = MysqlOptionFile::write($s->effectiveHost(), $s->effectivePort(), $s->username, $s->password);
        $this->tempFiles[] = $cnf;

        return $cnf;
    }

    /** @param list<array{0:string, 1:string}> $pairs */
    private function printSummary(AccessSettings $source, ?AccessSettings $target, bool $dumpOnly, array $pairs): void
    {
        $dest = $dumpOnly || $target === null
            ? 'none (dump only — writes a file)'
            : $target->describe().'  (DROPPED + recreated)';

        $dbList = implode(', ', array_map(
            static fn (array $p): string => $p[0] === $p[1] ? $p[0] : "{$p[0]} → {$p[1]}",
            $pairs
        ));

        $this->table(['Setting', 'Value'], [
            ['Source', $source->describe()],
            ['Destination', $dest],
            ['Databases ('.count($pairs).')', $dbList],
            ['Data only', $this->option('data-only') ? 'yes' : 'no'],
            ['Strip foreign keys', $this->option('strip-foreign-keys') ? 'yes' : 'no'],
        ]);
    }

    /** @param list<array{0:string, 1:string, 2:string}> $results */
    private function printResults(array $results, bool $dumpOnly): void
    {
        $this->line('');
        $this->table(
            ['Source DB', 'Target DB', 'Result'],
            array_map(
                static fn (array $r): array => [$r[0], $dumpOnly ? '—' : $r[1], $r[2]],
                $results
            )
        );
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
        $process = new Process(['bash', '-c', 'set -o pipefail; '.$command]);
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

    /** Tear down shared infrastructure once at the end: temp option files + tunnels. */
    private function closeInfra(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        $this->tempFiles = [];

        foreach ($this->tunnels as $tunnel) {
            $tunnel->close();
        }
        $this->tunnels = [];
    }
}
