<?php

namespace App\Services;

use App\Contracts\CommandExecutor;
use App\Models\Server;
use phpseclib3\Crypt\Common\AsymmetricKey;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;
use phpseclib3\Net\SSH2;
use RuntimeException;

class SshService implements CommandExecutor
{
    private ?string $host = null;

    private ?AsymmetricKey $key = null;

    private ?SFTP $sftp = null;

    private ?Server $server = null;

    public function connect(Server $server): self
    {
        $keyPath = config('cloud.ssh_private_key_path');
        $this->key = PublicKeyLoader::load(file_get_contents($keyPath));
        $this->host = $server->ipv4_address;
        $this->server = $server;

        // Verify SSH connectivity
        $ssh = new SSH2($this->host);
        if (! $ssh->login('root', $this->key)) {
            throw new RuntimeException("SSH login failed for server {$server->id}");
        }
        $ssh->disconnect();

        return $this;
    }

    public function connectToIp(string $ipAddress): self
    {
        $keyPath = config('cloud.ssh_private_key_path');
        $this->key = PublicKeyLoader::load(file_get_contents($keyPath));
        $this->host = $ipAddress;

        $ssh = new SSH2($this->host);
        if (! $ssh->login('root', $this->key)) {
            throw new RuntimeException("SSH login failed for {$ipAddress}");
        }
        $ssh->disconnect();

        return $this;
    }

    public function exec(string $command, int $timeout = 30): string
    {
        if (! $this->host || ! $this->key) {
            throw new RuntimeException('Not connected. Call connect() first.');
        }

        $ssh = new SSH2($this->host);
        $ssh->setTimeout($timeout);
        if (! $ssh->login('root', $this->key)) {
            throw new RuntimeException("SSH login failed for {$this->host}");
        }

        $output = $ssh->exec($command);
        $exitStatus = $ssh->getExitStatus();
        $ssh->disconnect();

        if ($exitStatus !== false && $exitStatus !== 0) {
            throw new RuntimeException("Command failed (exit {$exitStatus}): {$command}\nOutput: {$output}");
        }

        return $output;
    }

    public function upload(string $localPath, string $remotePath): void
    {
        $this->connectSftp();

        if (! $this->sftp->put($remotePath, $localPath, SFTP::SOURCE_LOCAL_FILE)) {
            throw new RuntimeException("Failed to upload {$localPath} to {$remotePath}");
        }
    }

    public function writeFile(string $remotePath, string $content): void
    {
        $this->connectSftp();

        if (! $this->sftp->put($remotePath, $content)) {
            throw new RuntimeException("Failed to write to {$remotePath}");
        }
    }

    public function readFile(string $remotePath): string
    {
        $this->connectSftp();

        $content = $this->sftp->get($remotePath);

        if ($content === false) {
            throw new RuntimeException("Failed to read {$remotePath}");
        }

        return $content;
    }

    /**
     * Execute a remote script by downloading it from a signed URL.
     * Reduces multiple SSH operations to a single curl | bash command.
     */
    public function execScript(string $signedUrl): string
    {
        // Setup scripts can take several minutes (openclaw onboard, package installs, etc.)
        return $this->exec("curl -fsSL '{$signedUrl}' | bash", 600);
    }

    /**
     * Execute a command with retry and exponential backoff.
     * For simple SSH operations that may fail due to connection instability.
     */
    public function execWithRetry(string $command, int $maxAttempts = 3, int $baseDelayMs = 2000): string
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                return $this->exec($command);
            } catch (RuntimeException $e) {
                $lastException = $e;

                if ($attempt < $maxAttempts) {
                    $delayMs = $baseDelayMs * pow(2, $attempt - 1);
                    usleep($delayMs * 1000);

                    // Reconnect if the connection dropped
                    if (str_contains($e->getMessage(), 'Connection closed') || str_contains($e->getMessage(), 'SSH')) {
                        try {
                            $this->disconnect();

                            if ($this->server) {
                                $this->connect($this->server);
                            }
                        } catch (RuntimeException) {
                            // Will retry on next iteration
                        }
                    }
                }
            }
        }

        throw $lastException;
    }

    public function disconnect(): void
    {
        $this->sftp?->disconnect();
        $this->sftp = null;
        $this->host = null;
        $this->key = null;
        $this->server = null;
    }

    private function connectSftp(): void
    {
        if (! $this->host || ! $this->key) {
            throw new RuntimeException('Not connected. Call connect() first.');
        }

        if (! $this->sftp) {
            $this->sftp = new SFTP($this->host);
            if (! $this->sftp->login('root', $this->key)) {
                throw new RuntimeException("SFTP login failed for {$this->host}");
            }
        }
    }
}
