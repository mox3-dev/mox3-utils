<?php

namespace App\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class SyncProductionDatabase extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'db:sync-production
                            {--connection=production : The production database connection name}
                            {--backup : Create a backup of local database before sync}
                            {--force : Skip confirmation prompt}
                            {--compress : Use gzip compression for faster transfer}
                            {--data-only : Dump data only, skip structure}';

    /**
     * The console command description.
     */
    protected $description = 'Sync production database to local development environment';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $productionConnection = $this->option('connection');
        $createBackup = $this->option('backup');
        $force = $this->option('force');

        // Verify production connection exists
        if (! config("database.connections.{$productionConnection}")) {
            $this->error("Production connection '{$productionConnection}' not found in database config.");
            $this->info('Available connections: '.implode(', ', array_keys(config('database.connections'))));

            return 1;
        }

        // Get local connection info
        $localConnection = config('database.default');
        $localConfig = config("database.connections.{$localConnection}");
        $productionConfig = config("database.connections.{$productionConnection}");

        $this->info("Syncing from {$productionConnection} to {$localConnection}");
        $this->line("Production: {$productionConfig['driver']} - {$productionConfig['database']}");
        $this->line("Local: {$localConfig['driver']} - {$localConfig['database']}");

        // Test SSH connection if configured
        $sshHost = env('PRODUCTION_SSH_HOST');
        $sshUser = env('PRODUCTION_SSH_USER', 'forge');

        if ($sshHost) {
            $this->info('Testing SSH connection...');
            $result = Process::timeout(15)->run(sprintf(
                'ssh -o StrictHostKeyChecking=no -o ConnectTimeout=10 %s@%s "echo ok"',
                escapeshellarg($sshUser),
                escapeshellarg($sshHost)
            ));

            if (! $result->successful()) {
                $this->error('Failed to connect via SSH: '.$result->errorOutput());

                return 1;
            }
            $this->info('✓ SSH connection successful');
        } else {
            // Direct connection - test it
            $this->info('Testing production database connection...');
            if (! $this->testConnection($productionConfig)) {
                $this->error('Failed to connect to production database.');

                return 1;
            }
            $this->info('✓ Production database connection successful');
        }

        // Confirmation
        if (! $force && ! $this->confirm('This will overwrite your local database. Continue?')) {
            $this->info('Operation cancelled.');

            return 0;
        }

        try {
            // Create backup if requested
            if ($createBackup) {
                $this->createLocalBackup($localConfig);
            }

            // Perform sync
            if ($sshHost) {
                $this->syncViaSsh($sshHost, $sshUser, $productionConfig, $localConfig);
            } else {
                $this->performSync($productionConfig, $localConfig);
            }

            $this->info('✓ Database sync completed successfully!');

        } catch (Exception $e) {
            $this->error('Database sync failed: '.$e->getMessage());

            return 1;
        }

        return 0;
    }

    protected function syncViaSsh(string $sshHost, string $sshUser, array $productionConfig, array $localConfig): void
    {
        $this->info('Dumping production database via SSH...');

        $tempDump = storage_path('temp/mysql_dump.sql');
        File::ensureDirectoryExists(dirname($tempDump));

        $dumpOptions = [
            '--single-transaction',
            '--quick',
            '--lock-tables=false',
            '--set-gtid-purged=OFF',
        ];

        if ($this->option('data-only')) {
            $dumpOptions[] = '--no-create-info';
        } else {
            $dumpOptions[] = '--routines';
            $dumpOptions[] = '--triggers';
        }

        $optionsStr = implode(' ', $dumpOptions);

        // Run mysqldump on the remote server via SSH and save locally
        $command = sprintf(
            'ssh -o StrictHostKeyChecking=no %s@%s "mysqldump -u%s -p%s %s %s" > %s',
            escapeshellarg($sshUser),
            escapeshellarg($sshHost),
            escapeshellarg($productionConfig['username']),
            escapeshellarg($productionConfig['password']),
            $optionsStr,
            escapeshellarg($productionConfig['database']),
            escapeshellarg($tempDump)
        );

        $result = Process::timeout(900)->run($command);

        if (! $result->successful()) {
            throw new Exception('Remote mysqldump failed: '.$result->errorOutput());
        }

        $this->info('✓ Remote dump completed');

        // Import to local MySQL
        $this->importMysqlDump($localConfig, $tempDump);

        // Cleanup
        File::delete($tempDump);
    }

    protected function createLocalBackup(array $localConfig): void
    {
        $this->info('Creating backup of local database...');

        $timestamp = now()->format('Y-m-d_H-i-s');
        $backupDir = storage_path('backups/database');
        File::ensureDirectoryExists($backupDir);

        if ($localConfig['driver'] === 'sqlite') {
            $backupPath = "{$backupDir}/local_backup_{$timestamp}.sqlite";
            File::copy($localConfig['database'], $backupPath);
            $this->info("✓ SQLite backup created: {$backupPath}");
        } else {
            $backupPath = "{$backupDir}/local_backup_{$timestamp}.sql";
            $this->createMysqlDump($localConfig, $backupPath);
            $this->info("✓ MySQL backup created: {$backupPath}");
        }
    }

    protected function performSync(array $productionConfig, array $localConfig): void
    {
        if ($productionConfig['driver'] === 'mysql' && $localConfig['driver'] === 'mysql') {
            $this->syncMysqlToMysql($productionConfig, $localConfig);
        } elseif ($productionConfig['driver'] === 'mysql' && $localConfig['driver'] === 'sqlite') {
            $this->syncMysqlToSqlite($productionConfig, $localConfig);
        } elseif ($productionConfig['driver'] === 'sqlite' && $localConfig['driver'] === 'sqlite') {
            $this->syncSqliteToSqlite($productionConfig, $localConfig);
        } else {
            throw new Exception("Unsupported database combination: {$productionConfig['driver']} to {$localConfig['driver']}");
        }
    }

    protected function syncSqliteToSqlite(array $production, array $local): void
    {
        $this->info('Copying SQLite database file...');

        if (! File::exists($production['database'])) {
            throw new Exception("Production SQLite file not found: {$production['database']}");
        }

        File::copy($production['database'], $local['database']);
    }

    protected function syncMysqlToSqlite(array $production, array $local): void
    {
        $this->info('Dumping MySQL data and importing to SQLite...');

        $tempDump = storage_path('temp/mysql_dump.sql');
        File::ensureDirectoryExists(dirname($tempDump));

        $this->createMysqlDump($production, $tempDump);

        if (File::exists($local['database'])) {
            File::delete($local['database']);
        }

        $this->call('migrate', ['--force' => true]);
        $this->importMysqlDumpToSqlite($tempDump);

        File::delete($tempDump);
    }

    protected function syncMysqlToMysql(array $production, array $local): void
    {
        $this->info('Dumping and importing MySQL data...');

        $tempDump = storage_path('temp/mysql_dump.sql');
        File::ensureDirectoryExists(dirname($tempDump));

        $this->createMysqlDump($production, $tempDump);
        $this->importMysqlDump($local, $tempDump);

        File::delete($tempDump);
    }

    protected function createMysqlDump(array $config, string $outputPath): void
    {
        $this->info('Creating MySQL dump (this may take several minutes for large databases)...');

        $options = [
            '--single-transaction',
            '--quick',
            '--lock-tables=false',
            '--set-gtid-purged=OFF',
        ];

        if ($this->option('data-only')) {
            $options[] = '--no-create-info';
        } else {
            $options[] = '--routines';
            $options[] = '--triggers';
        }

        $optionsStr = implode(' ', $options);

        $command = sprintf(
            'mysqldump -h%s -P%s -u%s -p%s %s %s > %s',
            escapeshellarg($config['host']),
            escapeshellarg($config['port']),
            escapeshellarg($config['username']),
            escapeshellarg($config['password']),
            $optionsStr,
            escapeshellarg($config['database']),
            escapeshellarg($outputPath)
        );

        $result = Process::timeout(900)->run($command);

        if (! $result->successful()) {
            throw new Exception('MySQL dump failed: '.$result->errorOutput());
        }

        $this->info('✓ MySQL dump completed');
    }

    protected function importMysqlDump(array $config, string $dumpPath): void
    {
        $this->info('Importing MySQL dump...');

        $passwordArg = empty($config['password']) ? '' : '-p'.escapeshellarg($config['password']);

        $command = sprintf(
            'mysql -h%s -P%s -u%s %s %s < %s',
            escapeshellarg($config['host']),
            escapeshellarg($config['port']),
            escapeshellarg($config['username']),
            $passwordArg,
            escapeshellarg($config['database']),
            escapeshellarg($dumpPath)
        );

        $result = Process::timeout(600)->run($command);

        if (! $result->successful()) {
            throw new Exception('MySQL import failed: '.$result->errorOutput());
        }

        $this->info('✓ MySQL import completed');
    }

    protected function importMysqlDumpToSqlite(string $dumpPath): void
    {
        $this->warn('MySQL to SQLite import uses basic SQL parsing. Complex schemas may need manual adjustment.');

        $sql = File::get($dumpPath);

        $sql = preg_replace('/ENGINE=\w+\s*(AUTO_INCREMENT=\d+\s*)?;/', ';', $sql);
        $sql = preg_replace('/AUTO_INCREMENT=\d+\s*/', '', $sql);
        $sql = str_replace('`', '"', $sql);

        DB::unprepared($sql);
    }

    protected function testConnection(array $config): bool
    {
        try {
            $command = sprintf(
                'mysqladmin -h%s -P%s -u%s -p%s ping',
                escapeshellarg($config['host']),
                escapeshellarg($config['port']),
                escapeshellarg($config['username']),
                escapeshellarg($config['password'])
            );

            $result = Process::timeout(30)->run($command);

            return $result->successful();
        } catch (Exception $e) {
            return false;
        }
    }
}
