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
