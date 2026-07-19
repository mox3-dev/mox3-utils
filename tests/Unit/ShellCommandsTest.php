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
        // Database is single-quoted in the remote mysqldump invocation.
        $this->assertStringContainsString("--defaults-extra-file=\"\$CNF\" --single-transaction 'app'", $cmd);
        // No -p password flag anywhere.
        $this->assertStringNotContainsString(' -p', $cmd);
    }

    public function test_reset_database_sql_escapes_backticks_in_name(): void
    {
        $sql = ShellCommands::resetDatabaseSql('we`ird');
        $this->assertStringContainsString('DROP DATABASE IF EXISTS `we``ird`', $sql);
    }
}
