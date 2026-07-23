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

    /**
     * Build the WHERE clause that keeps only the last N days of a log table.
     * Integer/decimal columns hold Unix epoch seconds, so the cutoff is wrapped
     * in UNIX_TIMESTAMP(); datetime/timestamp columns compare against DATE_SUB
     * directly. The column is always backtick-quoted (and any backtick in the
     * name is doubled).
     */
    public static function retentionCutoffSql(string $column, string $dataType, int $days): string
    {
        $isEpoch = in_array(
            strtolower($dataType),
            ['int', 'bigint', 'smallint', 'mediumint', 'tinyint', 'decimal', 'float', 'double'],
            true
        );

        $cutoff = $isEpoch
            ? "UNIX_TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL {$days} DAY))"
            : "DATE_SUB(CURDATE(), INTERVAL {$days} DAY)";

        $quoted = str_replace('`', '``', $column);

        return "`{$quoted}` >= {$cutoff}";
    }

    /**
     * Options for an append pass (one trimmed table). Same base flags as the main
     * pass, but never the DB-level flags --routines/--events (already dumped once
     * in pass 1). Triggers on the trimmed table are dumped here by mysqldump's
     * default; the table was --ignore-table'd in pass 1 so nothing duplicates.
     * --no-create-info only when dumping data only, matching the main pass.
     */
    public static function appendDumpOptions(bool $dataOnly): string
    {
        $opts = [
            '--single-transaction', '--quick', '--no-tablespaces',
            '--set-gtid-purged=OFF', '--default-character-set='.self::CHARSET,
        ];

        if ($dataOnly) {
            $opts[] = '--no-create-info';
        }

        return implode(' ', $opts);
    }

    /**
     * Main-pass dump over a direct/tunnelled connection. When $ignoreTables is
     * non-empty each table is excluded via --ignore-table=<db>.<table> (structure
     * and data both), so a later append pass can re-add it with a WHERE filter.
     * An empty list produces a byte-identical command to the plain full dump.
     *
     * @param list<string> $ignoreTables
     */
    public static function directDumpCommand(string $mysqldumpBin, string $cnf, string $options, string $database, string $dumpFile, array $ignoreTables = []): string
    {
        $ignore = '';
        foreach ($ignoreTables as $table) {
            $ignore .= ' --ignore-table='.escapeshellarg("{$database}.{$table}");
        }

        return sprintf(
            '%s --defaults-extra-file=%s %s%s %s | gzip > %s',
            escapeshellarg($mysqldumpBin),
            escapeshellarg($cnf),
            $options,
            $ignore,
            escapeshellarg($database),
            escapeshellarg($dumpFile)
        );
    }

    /**
     * Append pass over a direct/tunnelled connection: dump a single table through
     * a --where filter and APPEND it to the existing gzip. Concatenated gzip
     * members decompress as one stream, and this mysqldump emits its own
     * CREATE TABLE + filtered INSERTs, so the combined file is a valid dump.
     */
    public static function appendDumpCommand(string $mysqldumpBin, string $cnf, string $options, string $database, string $table, string $where, string $dumpFile): string
    {
        return sprintf(
            '%s --defaults-extra-file=%s %s --where=%s %s %s | gzip >> %s',
            escapeshellarg($mysqldumpBin),
            escapeshellarg($cnf),
            $options,
            escapeshellarg($where),
            escapeshellarg($database),
            escapeshellarg($table),
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
        string $dumpFile,
        array $ignoreTables = []
    ): string {
        $lines = self::remoteCnfSetupLines($username, $password);
        $lines[] = self::remoteDecodeVar('DB', $database);

        if ($ignoreTables !== []) {
            $lines[] = self::remoteDecodeVar('IGN_RAW', implode("\n", $ignoreTables));
            $lines[] = 'IGNARGS=()';
            $lines[] = 'while IFS= read -r t; do [ -n "$t" ] && IGNARGS+=(--ignore-table="$DB.$t"); done <<< "$IGN_RAW"';
            $lines[] = 'mysqldump --defaults-extra-file="$CNF" '.$options.' "${IGNARGS[@]}" -- "$DB"';
        } else {
            $lines[] = 'mysqldump --defaults-extra-file="$CNF" '.$options.' -- "$DB"';
        }

        $lines[] = 'rc=$?';
        $lines[] = 'rm -f "$CNF"';
        $lines[] = 'exit $rc';

        return sprintf(
            "ssh -o StrictHostKeyChecking=no %s bash -s <<'MOXDUMP' | gzip > %s\n%s\nMOXDUMP",
            escapeshellarg($sshTarget),
            escapeshellarg($dumpFile),
            implode("\n", $lines)
        );
    }

    /**
     * Append pass over SSH: dump one table through a --where filter and APPEND it
     * to the existing gzip. The WHERE clause and table name ship as base64 shell
     * variables (like the DB name) so untrusted bytes never sit literally in the
     * heredoc body, and credentials stay in the remote option file.
     */
    public static function sshAppendCommand(
        string $sshTarget,
        string $database,
        string $table,
        string $options,
        string $username,
        string $password,
        string $where,
        string $dumpFile
    ): string {
        $lines = self::remoteCnfSetupLines($username, $password);
        $lines[] = self::remoteDecodeVar('DB', $database);
        $lines[] = self::remoteDecodeVar('TBL', $table);
        $lines[] = self::remoteDecodeVar('WHERE', $where);
        $lines[] = 'mysqldump --defaults-extra-file="$CNF" '.$options.' --where="$WHERE" -- "$DB" "$TBL"';
        $lines[] = 'rc=$?';
        $lines[] = 'rm -f "$CNF"';
        $lines[] = 'exit $rc';

        return sprintf(
            "ssh -o StrictHostKeyChecking=no %s bash -s <<'MOXDUMP' | gzip >> %s\n%s\nMOXDUMP",
            escapeshellarg($sshTarget),
            escapeshellarg($dumpFile),
            implode("\n", $lines)
        );
    }

    /**
     * Run a read-only query on the remote source over SSH for introspection
     * (information_schema lookups). Batch mode (-N) prints tab-separated,
     * newline-delimited rows to stdout. The SQL travels as base64 (decoded into
     * "$SQL") and credentials live in the remote option file, so neither the
     * query text nor the password ever reaches argv.
     */
    public static function sshQueryCommand(
        string $sshTarget,
        string $username,
        string $password,
        string $sql
    ): string {
        $lines = self::remoteCnfSetupLines($username, $password);
        $lines[] = self::remoteDecodeVar('SQL', $sql);
        $lines[] = 'mysql --defaults-extra-file="$CNF" -N -e "$SQL"';
        $lines[] = 'rc=$?';
        $lines[] = 'rm -f "$CNF"';
        $lines[] = 'exit $rc';

        return sprintf(
            "ssh -o StrictHostKeyChecking=no %s bash -s <<'MOXQUERY'\n%s\nMOXQUERY",
            escapeshellarg($sshTarget),
            implode("\n", $lines)
        );
    }

    /**
     * The remote preamble that materializes a 0600 [client] option file from a
     * base64 blob shipped over the heredoc — so the password never reaches argv.
     *
     * @return list<string>
     */
    private static function remoteCnfSetupLines(string $username, string $password): array
    {
        $clientConfig = "[client]\n"
            .'user="'.self::escapeOptionValue($username)."\"\n"
            .'password="'.self::escapeOptionValue($password)."\"\n";

        return [
            'CNF="$(mktemp)" || exit 1',
            'chmod 600 "$CNF"',
            "printf '%s' ".self::singleQuote(base64_encode($clientConfig)).' | base64 -d > "$CNF"',
        ];
    }

    /** Build a remote `NAME="$(... base64 -d)"` line so $plain never appears literally. */
    private static function remoteDecodeVar(string $name, string $plain): string
    {
        return "{$name}=\"\$(printf '%s' ".self::singleQuote(base64_encode($plain))." | base64 -d)\"";
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

    /**
     * Strip DEFINER=`user`@`host` clauses from triggers/views/routines/events.
     * Runs always — the source server's account (e.g. root3@%) does not exist on
     * the target and would abort the import with Error 1449. LC_ALL=C so awk
     * treats the stream as raw bytes (binary blob bytes crash a UTF-8 locale awk).
     */
    public static function definerStripFilter(): string
    {
        return 'LC_ALL=C awk \'{ gsub(/DEFINER=`[^`]+`@`[^`]+`/, ""); print }\'';
    }

    /**
     * Physically remove `CONSTRAINT ... FOREIGN KEY` DDL lines (and fix the
     * dangling comma on the preceding line). Legacy FKs referencing non-unique
     * columns are rejected at CREATE time by MySQL 8.0.19+/8.4/9.x — a structural
     * rejection SET FOREIGN_KEY_CHECKS=0 cannot bypass. Anchored to DDL
     * indentation so data lines are untouched. LC_ALL=C for raw-byte safety.
     */
    public static function foreignKeyStripFilter(): string
    {
        return 'LC_ALL=C awk \'{ if ($0 ~ /^[[:space:]]*CONSTRAINT `[^`]+` FOREIGN KEY/) next; if (h) { if ($0 ~ /^\)/ && p ~ /,[[:space:]]*$/) sub(/,[[:space:]]*$/, "", p); print p } p=$0; h=1 } END { if (h) print p }\'';
    }

    public static function importCommand(string $mysqlBin, string $cnf, string $database, string $dumpFile, bool $stripForeignKeys = false): string
    {
        $preamble = "SET SESSION innodb_strict_mode=0; SET FOREIGN_KEY_CHECKS=0; SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';";
        $postamble = 'SET FOREIGN_KEY_CHECKS=1;';

        $filters = self::definerStripFilter();
        if ($stripForeignKeys) {
            $filters .= ' | '.self::foreignKeyStripFilter();
        }

        return sprintf(
            '( echo %s; gunzip < %s; echo %s ) | %s | %s --defaults-extra-file=%s --max_allowed_packet=512M --net_buffer_length=16384 %s',
            escapeshellarg($preamble),
            escapeshellarg($dumpFile),
            escapeshellarg($postamble),
            $filters,
            escapeshellarg($mysqlBin),
            escapeshellarg($cnf),
            escapeshellarg($database)
        );
    }
}
