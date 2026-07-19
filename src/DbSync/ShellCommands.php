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
