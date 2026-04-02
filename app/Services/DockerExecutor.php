<?php

namespace App\Services;

use App\Contracts\CommandExecutor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class DockerExecutor implements CommandExecutor
{
    private string $container;

    public function __construct()
    {
        $this->container = config('provision.docker.container', 'provision-agent-runtime-1');
    }

    public function exec(string $command): string
    {
        $result = Process::run(['docker', 'exec', $this->container, 'bash', '-c', $command]);

        if ($result->failed()) {
            throw new RuntimeException("Command failed (exit {$result->exitCode()}): {$command}\nOutput: {$result->output()}{$result->errorOutput()}");
        }

        return $result->output();
    }

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
                }
            }
        }

        throw $lastException;
    }

    public function writeFile(string $path, string $content): void
    {
        $this->exec('mkdir -p '.escapeshellarg(dirname($path)));

        $tempFile = tempnam(sys_get_temp_dir(), 'provision-');
        file_put_contents($tempFile, $content);

        try {
            $result = Process::run(['docker', 'cp', $tempFile, "{$this->container}:{$path}"]);

            if ($result->failed()) {
                throw new RuntimeException("Failed to write {$path}: {$result->errorOutput()}");
            }
        } finally {
            @unlink($tempFile);
        }
    }

    public function readFile(string $path): string
    {
        return $this->exec('cat '.escapeshellarg($path));
    }

    public function execScript(string $script): string
    {
        // If the script is a URL (signed URL to Provision API), resolve it locally
        // We can't use file_get_contents because the PHP dev server is single-threaded
        // and would deadlock when the Horizon worker tries to call back to itself.
        if (str_starts_with($script, 'http://') || str_starts_with($script, 'https://')) {
            $parsed = parse_url($script);
            $path = $parsed['path'] ?? '';
            $query = $parsed['query'] ?? '';

            $response = app()->handle(
                Request::create("{$path}?{$query}", 'GET')
            );

            if ($response->getStatusCode() !== 200) {
                throw new RuntimeException("Failed to resolve script URL (HTTP {$response->getStatusCode()})");
            }

            $script = $response->getContent();
        }

        $scriptId = uniqid();
        $remotePath = "/tmp/provision-script-{$scriptId}.sh";

        $tempFile = tempnam(sys_get_temp_dir(), 'provision-');
        file_put_contents($tempFile, $script);

        try {
            $result = Process::run(['docker', 'cp', $tempFile, "{$this->container}:{$remotePath}"]);

            if ($result->failed()) {
                throw new RuntimeException("Failed to copy script to container: {$result->errorOutput()}");
            }
        } finally {
            @unlink($tempFile);
        }

        try {
            return $this->exec("bash {$remotePath}");
        } finally {
            try {
                $this->exec("rm -f {$remotePath}");
            } catch (RuntimeException) {
                // Cleanup failure is non-fatal
            }
        }
    }
}
