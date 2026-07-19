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

    /**
     * Canonical identity of the physical database endpoint, normalized across
     * access strategies so a direct/tunnel pair pointing at the same remote DB
     * compares equal. Used to refuse pushing into the source being dumped.
     */
    public function identity(): string
    {
        if ($this->isTunnel()) {
            return $this->normalizeHostPort((string) $this->tunnelRemote);
        }

        if ($this->isSsh()) {
            return strtolower((string) $this->sshTarget).'/'.$this->normalizeHostPort($this->host.':'.$this->port);
        }

        return $this->normalizeHostPort($this->host.':'.$this->port);
    }

    private function normalizeHostPort(string $hostPort): string
    {
        [$host, $port] = array_pad(explode(':', $hostPort, 2), 2, '3306');

        return strtolower(trim($host)).':'.trim($port);
    }

    /**
     * Human-readable description of where this endpoint physically lives, with
     * the access strategy made explicit — so an ssh/tunnel source is never
     * mistaken for localhost in command output.
     */
    public function describe(): string
    {
        return match ($this->access) {
            'ssh' => sprintf('%s @ %s (ssh → remote MySQL)', $this->database, $this->sshTarget),
            'tunnel' => sprintf('%s @ %s (tunnel via %s, local :%s)', $this->database, $this->tunnelRemote, $this->sshTarget, $this->tunnelLocalPort),
            default => sprintf('%s @ %s:%s (direct)', $this->database, $this->host, $this->port),
        };
    }
}
