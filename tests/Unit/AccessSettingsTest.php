<?php

namespace Mox3\Utils\Tests\Unit;

use Mox3\Utils\DbSync\AccessSettings;
use PHPUnit\Framework\TestCase;

class AccessSettingsTest extends TestCase
{
    private function env(array $map): callable
    {
        return fn (string $key, mixed $default = null): mixed => $map[$key] ?? $default;
    }

    public function test_direct_is_the_default_access(): void
    {
        $s = AccessSettings::fromConnection('production', [
            'host' => 'db.example.com', 'port' => 3306,
            'username' => 'u', 'password' => 'p', 'database' => 'app',
        ], $this->env([]));

        $this->assertTrue($s->isDirect());
        $this->assertSame('db.example.com', $s->effectiveHost());
        $this->assertSame(3306, $s->effectivePort());
    }

    public function test_tunnel_rewrites_effective_endpoint_to_local_port(): void
    {
        $s = AccessSettings::fromConnection('production', [
            'host' => '127.0.0.1', 'port' => 3306,
            'username' => 'u', 'password' => 'p', 'database' => 'app',
        ], $this->env([
            'PRODUCTION_ACCESS' => 'tunnel',
            'PRODUCTION_TUNNEL_SSH' => 'bastion@ec2',
            'PRODUCTION_TUNNEL_REMOTE' => 'db.internal:3306',
            'PRODUCTION_TUNNEL_LOCAL_PORT' => '13306',
        ]));

        $this->assertTrue($s->isTunnel());
        $this->assertSame('bastion@ec2', $s->sshTarget);
        $this->assertSame('db.internal:3306', $s->tunnelRemote);
        $this->assertSame(13306, $s->tunnelLocalPort);
        $this->assertSame('127.0.0.1', $s->effectiveHost());
        $this->assertSame(13306, $s->effectivePort());
    }

    public function test_ssh_reads_ssh_target_and_hyphenated_connection_name(): void
    {
        $s = AccessSettings::fromConnection('staging-db', [
            'host' => 'h', 'port' => 3306, 'username' => 'u', 'password' => 'p', 'database' => 'd',
        ], $this->env([
            'STAGING_DB_ACCESS' => 'ssh',
            'STAGING_DB_SSH' => 'forge@1.2.3.4',
        ]));

        $this->assertTrue($s->isSsh());
        $this->assertSame('forge@1.2.3.4', $s->sshTarget);
    }
}
