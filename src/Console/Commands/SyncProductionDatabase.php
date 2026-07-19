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
            : $target->describe().'  (DROPPED + recreated)';

        $this->table(['Setting', 'Value'], [
            ['Source', $source->describe()],
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
