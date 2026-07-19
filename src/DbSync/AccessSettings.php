<?php

namespace Mox3\Utils\DbSync;

final class AccessSettings
{
    public function __construct(
        public readonly string $access,
        public readonly string $host,
        public readonly int $port,
        public readonly string $username,
        public readonly string $password,
        public readonly string $database,
        public readonly ?string $sshTarget = null,
        public readonly ?string $tunnelRemote = null,
        public readonly ?int $tunnelLocalPort = null,
    ) {}

    public static function fromConnection(string $connectionName, array $config, callable $env): self
    {
        $prefix = strtoupper(str_replace('-', '_', $connectionName)).'_';
        $access = strtolower((string) $env($prefix.'ACCESS', 'direct'));

        $sshTarget = $env($prefix.'SSH') ?: $env($prefix.'TUNNEL_SSH');
        $localPort = $env($prefix.'TUNNEL_LOCAL_PORT');

        return new self(
            access: in_array($access, ['direct', 'ssh', 'tunnel'], true) ? $access : 'direct',
            host: (string) ($config['host'] ?? '127.0.0.1'),
            port: (int) ($config['port'] ?? 3306),
            username: (string) ($config['username'] ?? ''),
            password: (string) ($config['password'] ?? ''),
            database: (string) ($config['database'] ?? ''),
            sshTarget: $sshTarget !== null ? (string) $sshTarget : null,
            tunnelRemote: ($r = $env($prefix.'TUNNEL_REMOTE')) !== null ? (string) $r : null,
            tunnelLocalPort: $localPort !== null ? (int) $localPort : null,
        );
    }

    public function isDirect(): bool { return $this->access === 'direct'; }

    public function isSsh(): bool { return $this->access === 'ssh'; }

    public function isTunnel(): bool { return $this->access === 'tunnel'; }

    public function effectiveHost(): string
    {
        return $this->isTunnel() ? '127.0.0.1' : $this->host;
    }

    public function effectivePort(): int
    {
        return $this->isTunnel() ? (int) $this->tunnelLocalPort : $this->port;
    }
}
