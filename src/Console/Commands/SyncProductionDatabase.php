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
