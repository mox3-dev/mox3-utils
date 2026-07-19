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
        $name = str_replace('`', '``', $database);

        return sprintf(
            'SET FOREIGN_KEY_CHECKS=0; DROP DATABASE IF EXISTS `%1$s`; '
            .'CREATE DATABASE `%1$s` CHARACTER SET %2$s COLLATE %2$s_unicode_ci; SET FOREIGN_KEY_CHECKS=1;',
            $name,
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
     * credentials from a temp 0600 option file we ship as base64 over the SSH
     * stdin (a quoted heredoc bound to ssh, before the gzip pipe) — so no
     * password ever reaches any argv, the base64 payload cannot collide with
     * the heredoc terminator, and the database name now travels as base64 and
     * is decoded into a remote shell variable (quoted "$DB"), never embedded
     * as literal text. ssh's stdout is piped to gzip into the local dump file.
     * The database name also travels as base64 and is decoded into a remote
     * shell variable, so untrusted bytes (e.g. a newline matching the heredoc
     * terminator) are never embedded as literal text in the local heredoc body.
     */
    public static function sshDumpCommand(
        string $sshTarget,
        string $database,
        string $options,
        string $username,
        string $password,
        string $dumpFile
    ): string {
        $clientConfig = "[client]\n"
            .'user="'.self::escapeOptionValue($username)."\"\n"
            .'password="'.self::escapeOptionValue($password)."\"\n";

        $script = implode("\n", [
            'CNF="$(mktemp)" || exit 1',
            'chmod 600 "$CNF"',
            "printf '%s' ".self::singleQuote(base64_encode($clientConfig)).' | base64 -d > "$CNF"',
            "DB=\"\$(printf '%s' ".self::singleQuote(base64_encode($database))." | base64 -d)\"",
            'mysqldump --defaults-extra-file="$CNF" '.$options.' -- "$DB"',
            'rc=$?',
            'rm -f "$CNF"',
            'exit $rc',
        ]);

        return sprintf(
            "ssh -o StrictHostKeyChecking=no %s bash -s <<'MOXDUMP' | gzip > %s\n%s\nMOXDUMP",
            escapeshellarg($sshTarget),
            escapeshellarg($dumpFile),
            $script
        );
    }

    private static function escapeOptionValue(string $value): string
    {
        return str_replace(
            ['\\', '"', "\n", "\r", "\t"],
            ['\\\\', '\\"', '\\n', '\\r', '\\t'],
            $value
        );
    }

    private static function singleQuote(string $value): string
    {
        return "'".str_replace("'", "'\\''", $value)."'";
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
