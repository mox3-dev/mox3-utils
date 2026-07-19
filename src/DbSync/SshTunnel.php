<?php

namespace Mox3\Utils\DbSync;

use RuntimeException;
use Symfony\Component\Process\Process;

final class SshTunnel
{
    private ?Process $process = null;

    public function __construct(
        private readonly string $sshTarget,
        private readonly string $tunnelRemote,
        private readonly int $localPort,
    ) {}

    public function open(int $waitSeconds = 15): void
    {
        $this->process = new Process(
            ShellCommands::tunnelArgs($this->sshTarget, $this->tunnelRemote, $this->localPort)
        );
        $this->process->setTimeout(null);
        $this->process->start();

        $deadline = time() + $waitSeconds;
        while (time() < $deadline) {
            $conn = @fsockopen('127.0.0.1', $this->localPort, $errno, $errstr, 1);
            if ($conn !== false) {
                fclose($conn);

                return;
            }
            if (! $this->process->isRunning()) {
                throw new RuntimeException('SSH tunnel exited early: '.$this->process->getErrorOutput());
            }
            usleep(300_000);
        }

        $this->close();
        throw new RuntimeException("SSH tunnel did not open on 127.0.0.1:{$this->localPort} within {$waitSeconds}s.");
    }

    public function close(): void
    {
        if ($this->process !== null && $this->process->isRunning()) {
            $this->process->stop(5);
        }
        $this->process = null;
    }
}
