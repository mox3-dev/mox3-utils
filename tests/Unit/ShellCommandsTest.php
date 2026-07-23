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

    public function test_append_dump_options_omit_structure_level_flags(): void
    {
        $opts = ShellCommands::appendDumpOptions(false);
        // Base dump flags are present...
        $this->assertStringContainsString('--single-transaction', $opts);
        $this->assertStringContainsString('--set-gtid-purged=OFF', $opts);
        // ...but the DB-level structure flags are NOT re-emitted in the append pass.
        $this->assertStringNotContainsString('--routines', $opts);
        $this->assertStringNotContainsString('--triggers', $opts);
        $this->assertStringNotContainsString('--events', $opts);
        // Not data-only: the trimmed table keeps its CREATE TABLE.
        $this->assertStringNotContainsString('--no-create-info', $opts);
    }

    public function test_append_dump_options_respect_data_only(): void
    {
        $this->assertStringContainsString('--no-create-info', ShellCommands::appendDumpOptions(true));
    }

    public function test_direct_dump_command_with_no_ignore_tables_is_byte_identical(): void
    {
        $without = ShellCommands::directDumpCommand('mysqldump', '/tmp/cnf', '--single-transaction', 'app', '/tmp/out.sql.gz');
        $withEmpty = ShellCommands::directDumpCommand('mysqldump', '/tmp/cnf', '--single-transaction', 'app', '/tmp/out.sql.gz', []);
        $this->assertSame($without, $withEmpty);
        $this->assertStringNotContainsString('--ignore-table', $without);
    }

    public function test_direct_dump_command_emits_one_ignore_table_per_entry(): void
    {
        $cmd = ShellCommands::directDumpCommand(
            'mysqldump', '/tmp/cnf', '--single-transaction', 'app', '/tmp/out.sql.gz',
            ['logs_api_v3', 'log']
        );
        $this->assertStringContainsString("--ignore-table='app.logs_api_v3'", $cmd);
        $this->assertStringContainsString("--ignore-table='app.log'", $cmd);
        // Ignore args precede the database and the gzip pipe stays intact.
        $this->assertMatchesRegularExpression("/--ignore-table='app.log' .*'app' \\| gzip > /", $cmd);
    }

    public function test_direct_append_command_filters_with_where_and_appends(): void
    {
        $cmd = ShellCommands::appendDumpCommand(
            'mysqldump', '/tmp/cnf', '--single-transaction', 'app', 'logs_api_v3',
            '`timestamp` >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)', '/tmp/out.sql.gz'
        );
        $this->assertStringContainsString('--defaults-extra-file', $cmd);
        $this->assertStringContainsString('--where=', $cmd);
        $this->assertStringContainsString('DATE_SUB(CURDATE(), INTERVAL 30 DAY)', $cmd);
        // Dumps exactly the one table, and APPENDS to the existing gzip.
        $this->assertMatchesRegularExpression("/'app' 'logs_api_v3' \\| gzip >> /", $cmd);
        $this->assertStringNotContainsString(' -p', $cmd);
    }

    public function test_import_command_gunzips_into_mysql_client(): void
    {
        $cmd = ShellCommands::importCommand('mysql', '/tmp/cnf', 'app', '/tmp/out.sql.gz');
        $this->assertStringContainsString('gunzip <', $cmd);
        $this->assertStringContainsString('--defaults-extra-file', $cmd);
        // Always wraps the stream in the legacy-data session pragmas...
        $this->assertStringContainsString('SET FOREIGN_KEY_CHECKS=0', $cmd);
        $this->assertStringContainsString("SQL_MODE=", $cmd);
        $this->assertStringContainsString('SET FOREIGN_KEY_CHECKS=1', $cmd);
        // ...pipes through the DEFINER-strip filter, and raises net_buffer_length.
        $this->assertStringContainsString(ShellCommands::definerStripFilter(), $cmd);
        $this->assertStringContainsString('--net_buffer_length=16384', $cmd);
    }

    public function test_import_command_without_flag_strips_definer_but_not_foreign_keys(): void
    {
        $cmd = ShellCommands::importCommand('mysql', '/tmp/cnf', 'app', '/tmp/out.sql.gz', false);
        $this->assertStringContainsString(ShellCommands::definerStripFilter(), $cmd);
        $this->assertStringContainsString('SET FOREIGN_KEY_CHECKS=0', $cmd);
        // The FK-strip awk program is NOT present when the flag is off.
        $this->assertStringNotContainsString(ShellCommands::foreignKeyStripFilter(), $cmd);
        $this->assertStringNotContainsString('CONSTRAINT', $cmd);
    }

    public function test_import_command_with_flag_also_strips_foreign_keys(): void
    {
        $cmd = ShellCommands::importCommand('mysql', '/tmp/cnf', 'app', '/tmp/out.sql.gz', true);
        $this->assertStringContainsString(ShellCommands::definerStripFilter(), $cmd);
        $this->assertStringContainsString(ShellCommands::foreignKeyStripFilter(), $cmd);
    }

    public function test_strip_filters_run_under_raw_byte_locale_awk(): void
    {
        $this->assertStringStartsWith('LC_ALL=C awk', ShellCommands::definerStripFilter());
        $this->assertStringStartsWith('LC_ALL=C awk', ShellCommands::foreignKeyStripFilter());
    }

    public function test_ssh_dump_command_ships_credentials_as_base64_over_ssh_stdin(): void
    {
        $cmd = ShellCommands::sshDumpCommand(
            'forge@host', 'app', '--single-transaction', 'root', 's3cr3t"pw', '/tmp/out.sql.gz'
        );

        // Heredoc binds to ssh (before the gzip pipe) so the remote actually runs.
        $this->assertMatchesRegularExpression("/ssh .* bash -s <<'MOXDUMP' \\| gzip > /s", $cmd);
        // Password never appears in plaintext — it travels inside the base64 blob.
        $this->assertStringNotContainsString('s3cr3t"pw', $cmd);
        $expectedConfig = "[client]\nuser=\"root\"\npassword=\"s3cr3t\\\"pw\"\n";
        $this->assertStringContainsString(base64_encode($expectedConfig), $cmd);
        // Database is shipped as base64 and used as a quoted, decoded var — never literal text.
        $this->assertStringContainsString(base64_encode('app'), $cmd);
        $this->assertStringContainsString('mysqldump --defaults-extra-file="$CNF" --single-transaction -- "$DB"', $cmd);
        // No -p password flag anywhere.
        $this->assertStringNotContainsString(' -p', $cmd);
    }

    public function test_ssh_dump_command_with_no_ignore_tables_is_byte_identical(): void
    {
        $without = ShellCommands::sshDumpCommand('forge@host', 'app', '--single-transaction', 'root', 'pw', '/tmp/out.sql.gz');
        $withEmpty = ShellCommands::sshDumpCommand('forge@host', 'app', '--single-transaction', 'root', 'pw', '/tmp/out.sql.gz', []);
        $this->assertSame($without, $withEmpty);
        $this->assertStringNotContainsString('ignore-table', $without);
    }

    public function test_ssh_dump_command_ships_ignore_tables_as_base64_never_literal(): void
    {
        $cmd = ShellCommands::sshDumpCommand(
            'forge@host', 'app', '--single-transaction', 'root', 'pw', '/tmp/out.sql.gz',
            ['logs_api_v3', 'log']
        );
        // Table names travel only inside the base64 blob, never as literal heredoc bytes.
        $this->assertStringContainsString(base64_encode("logs_api_v3\nlog"), $cmd);
        $this->assertStringNotContainsString('logs_api_v3', str_replace(base64_encode("logs_api_v3\nlog"), '', $cmd));
        // The remote builds --ignore-table args and still dumps to the same gzip file.
        $this->assertStringContainsString('--ignore-table="$DB.$t"', $cmd);
        $this->assertMatchesRegularExpression("/<<'MOXDUMP' \\| gzip > /s", $cmd);
        // Heredoc delimiter appears exactly twice (opener + terminator) — nothing injected.
        $this->assertSame(2, substr_count($cmd, 'MOXDUMP'));
    }

    public function test_ssh_append_command_filters_with_where_and_appends(): void
    {
        $cmd = ShellCommands::sshAppendCommand(
            'forge@host', 'app', 'logs_api_v3', '--single-transaction', 'root', 's3cr3t"pw',
            '`timestamp` >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)', '/tmp/out.sql.gz'
        );
        // Appends to the existing gzip, over ssh.
        $this->assertMatchesRegularExpression("/<<'MOXDUMP' \\| gzip >> /s", $cmd);
        // Password, where clause, and table name all ship as base64 — never plaintext.
        $this->assertStringNotContainsString('s3cr3t"pw', $cmd);
        $this->assertStringNotContainsString('DATE_SUB(CURDATE(), INTERVAL 30 DAY)', $cmd);
        $this->assertStringContainsString(base64_encode('`timestamp` >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)'), $cmd);
        $this->assertStringContainsString(base64_encode('logs_api_v3'), $cmd);
        $this->assertStringContainsString('mysqldump --defaults-extra-file="$CNF" --single-transaction --where="$WHERE" -- "$DB" "$TBL"', $cmd);
    }

    public function test_ssh_query_command_runs_mysql_with_base64_sql_and_no_plaintext_creds(): void
    {
        $sql = "SELECT table_name FROM information_schema.tables WHERE table_schema='app'";
        $cmd = ShellCommands::sshQueryCommand('forge@host', 'root', 's3cr3t"pw', $sql);

        // Runs a batch (-N) query; SQL travels as base64, decoded into a shell var.
        $this->assertStringContainsString('mysql --defaults-extra-file="$CNF" -N -e "$SQL"', $cmd);
        $this->assertStringContainsString(base64_encode($sql), $cmd);
        $this->assertStringNotContainsString('information_schema.tables', str_replace(base64_encode($sql), '', $cmd));
        // Credentials ship inside the option file, never on argv.
        $this->assertStringNotContainsString('s3cr3t"pw', $cmd);
        $this->assertStringNotContainsString(' -p', $cmd);
        // No gzip — the rows come back on stdout.
        $this->assertStringNotContainsString('gzip', $cmd);
    }

    public function test_ssh_dump_command_neutralizes_newline_injection_in_database_name(): void
    {
        $malicious = "x\nMOXDUMP\necho INJECTED\n";
        $cmd = ShellCommands::sshDumpCommand('h', $malicious, '--quick', 'u', 'p', '/tmp/o.gz');

        // The malicious text is never present as literal heredoc-body content...
        $this->assertStringNotContainsString('echo INJECTED', $cmd);
        // ...only its base64 form is.
        $this->assertStringContainsString(base64_encode($malicious), $cmd);
        // Exactly the opener + terminator occurrences of the delimiter, nothing injected.
        $this->assertSame(2, substr_count($cmd, 'MOXDUMP'));
    }

    public function test_reset_database_sql_escapes_backticks_in_name(): void
    {
        $sql = ShellCommands::resetDatabaseSql('we`ird');
        $this->assertStringContainsString('DROP DATABASE IF EXISTS `we``ird`', $sql);
    }

    public function test_retention_cutoff_uses_date_sub_for_datetime_columns(): void
    {
        $where = ShellCommands::retentionCutoffSql('created_at', 'datetime', 30);
        $this->assertSame('`created_at` >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)', $where);

        $this->assertSame(
            '`login` >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)',
            ShellCommands::retentionCutoffSql('login', 'timestamp', 7)
        );
    }

    public function test_retention_cutoff_wraps_epoch_columns_in_unix_timestamp(): void
    {
        foreach (['int', 'bigint', 'smallint', 'mediumint', 'tinyint', 'decimal', 'float', 'double'] as $type) {
            $where = ShellCommands::retentionCutoffSql('timestamp', $type, 30);
            $this->assertSame(
                '`timestamp` >= UNIX_TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 30 DAY))',
                $where,
                "epoch handling for {$type}"
            );
        }
    }

    public function test_retention_cutoff_is_case_insensitive_on_data_type(): void
    {
        $this->assertStringContainsString('UNIX_TIMESTAMP', ShellCommands::retentionCutoffSql('t', 'INT', 30));
        $this->assertStringNotContainsString('UNIX_TIMESTAMP', ShellCommands::retentionCutoffSql('t', 'DATETIME', 30));
    }

    public function test_retention_cutoff_doubles_backticks_in_column_name(): void
    {
        $where = ShellCommands::retentionCutoffSql('we`ird', 'datetime', 30);
        $this->assertStringStartsWith('`we``ird` >=', $where);
    }
}
