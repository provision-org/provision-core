<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;

class AsciiBoxService
{
    private const BOX_ID_PATTERN = '/\Abx_[23456789abcdefghjkmnpqrstuvwxyz]{8}\z/';

    /**
     * Bound the cost of a Box whose create response is lost before Provision
     * can persist its id. Normal boxes have auto-stop disabled immediately
     * after their id is stored.
     */
    private const CREATE_SAFETY_TTL_SECONDS = 3600;

    /**
     * Box states where setup commands are safe to run.
     *
     * @var list<string>
     */
    private const READY_STATES = ['ready', 'idle'];

    private PendingRequest $http;

    public function __construct(?string $apiToken = null)
    {
        $apiToken ??= config('cloud.ascii.api_token');

        if (! is_string($apiToken) || trim($apiToken) === '') {
            throw new RuntimeException('ASCII Box API key is not configured.');
        }

        $this->http = Http::baseUrl(rtrim(config('cloud.ascii.base_url'), '/'))
            ->withToken($apiToken)
            ->acceptJson()
            ->connectTimeout(10)
            ->timeout(70);
    }

    /**
     * @return array<string, mixed>
     */
    public function getLimits(): array
    {
        $response = $this->http->get('/limits');
        $response->throw();

        return $response->json() ?? [];
    }

    /**
     * @param  array<string, string>  $environment
     * @return array<string, mixed>
     */
    public function createBox(array $environment = []): array
    {
        $response = $this->http->post('/boxes', [
            'ttlSeconds' => self::CREATE_SAFETY_TTL_SECONDS,
            'noEnv' => true,
            'env' => $environment,
        ]);

        $response->throw();

        return $response->json('box') ?? [];
    }

    public function disableAutoStop(string $boxId): void
    {
        $response = $this->http
            ->retry(3, 500)
            ->patch("/boxes/{$boxId}", [
                'ttlSeconds' => null,
            ]);
        $response->throw();
    }

    /**
     * @return array<string, mixed>
     */
    public function getBox(string $boxId): array
    {
        $response = $this->http->get("/boxes/{$boxId}");
        $response->throw();

        return $response->json('box') ?? [];
    }

    /**
     * Mint a fresh, secret-bearing URL for the Box's shared desktop.
     *
     * The URL is deliberately returned only to the caller and must never be
     * persisted or logged.
     */
    public function getDesktopUrl(string $boxId): string
    {
        if (! preg_match(self::BOX_ID_PATTERN, $boxId)) {
            throw new RuntimeException('The ASCII Box id is invalid.');
        }

        $response = $this->http->post("/boxes/{$boxId}/desktop?theme=light");
        $response->throw();

        $desktopUrl = $response->json('desktopUrl');

        if (! is_string($desktopUrl)
            || filter_var($desktopUrl, FILTER_VALIDATE_URL) === false
            || parse_url($desktopUrl, PHP_URL_SCHEME) !== 'https'
            || ! is_string(parse_url($desktopUrl, PHP_URL_HOST))) {
            throw new RuntimeException('The ASCII Box desktop is not available yet.');
        }

        return $desktopUrl;
    }

    /**
     * Wait until Box has applied its environment and assigned an IPv4 address.
     *
     * @return array<string, mixed>
     */
    public function waitUntilReady(string $boxId, int $attempts = 60, int $delaySeconds = 2): array
    {
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $box = $this->getBox($boxId);
            $state = $box['state'] ?? null;

            if (in_array($state, self::READY_STATES, true) && ! empty($box['ip'])) {
                return $box;
            }

            if (in_array($state, ['error', 'archiving', 'archived'], true)) {
                throw new RuntimeException("ASCII Box {$boxId} entered state {$state} before becoming ready.");
            }

            if ($attempt < $attempts) {
                Sleep::sleep($delaySeconds);
            }
        }

        throw new RuntimeException("ASCII Box {$boxId} did not become ready in time.");
    }

    /**
     * Authorize Provision's public key for the documented `user` account and
     * the root account used by the existing OpenClaw SSH/SFTP runtime.
     */
    public function configureProvisionSsh(string $boxId, string $publicKey): void
    {
        $publicKey = trim($publicKey);

        if ($publicKey === '' || str_contains($publicKey, 'PRIVATE KEY')) {
            throw new RuntimeException('A valid public SSH key is required for ASCII Box provisioning.');
        }

        $response = $this->http->post("/boxes/{$boxId}/sshkey", [
            'key' => $publicKey,
        ]);
        $response->throw();

        $encodedKey = base64_encode($publicKey);
        $rootSshScript = <<<BASH
        set -e
        install -d -m 0700 /root/.ssh
        touch /root/.ssh/authorized_keys
        PUBLIC_KEY=\$(printf '%s' '{$encodedKey}' | base64 -d)
        grep -qxF "\$PUBLIC_KEY" /root/.ssh/authorized_keys \
          || printf '%s\\n' "\$PUBLIC_KEY" >> /root/.ssh/authorized_keys
        chmod 0600 /root/.ssh/authorized_keys
        install -d -m 0755 /etc/ssh/sshd_config.d
        printf '%s\\n' \
          'PermitRootLogin prohibit-password' \
          'PubkeyAuthentication yes' \
          > /etc/ssh/sshd_config.d/99-provision-root.conf
        /usr/sbin/sshd -t
        systemctl reload ssh
        BASH;

        $encodedRootSshScript = base64_encode($rootSshScript);
        $command = "printf '%s' ".escapeshellarg($encodedRootSshScript).' | base64 -d | sudo bash';

        $this->executeCommand($boxId, $command);
    }

    public function startBootstrap(string $boxId, string $script): void
    {
        $encodedScript = base64_encode($script);
        $launcher = <<<BASH
        #!/bin/bash
        set -e
        install -d -m 0755 /var/lib/provision
        if [ -f /var/lib/provision/bootstrap-complete ]; then
            exit 0
        fi
        printf '%s' '{$encodedScript}' | base64 -d > /var/lib/provision/bootstrap.sh
        chmod 0700 /var/lib/provision/bootstrap.sh
        nohup flock -n /var/lock/provision-bootstrap.lock bash -c \
          '/var/lib/provision/bootstrap.sh && touch /var/lib/provision/bootstrap-complete' \
          </dev/null >/var/log/provision-bootstrap.log 2>&1 &
        BASH;

        $encodedLauncher = base64_encode($launcher);
        $command = "printf '%s' ".escapeshellarg($encodedLauncher).' | base64 -d | sudo bash';

        $this->executeCommand($boxId, $command);
    }

    public function archiveBox(string $boxId, int $attempts = 60, int $delaySeconds = 2): void
    {
        try {
            $box = $this->getBox($boxId);
        } catch (RequestException $e) {
            if ($e->response?->status() === 404) {
                return;
            }

            throw $e;
        }

        if (($box['state'] ?? null) === 'archived') {
            return;
        }

        $response = $this->http->post("/boxes/{$boxId}/stop");

        if ($response->status() === 404) {
            return;
        }

        $response->throw();

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $box = $this->getBox($boxId);
            $state = $box['state'] ?? null;

            if ($state === 'archived') {
                return;
            }

            if ($state === 'error') {
                throw new RuntimeException("ASCII Box {$boxId} entered an error state while archiving.");
            }

            if ($attempt < $attempts) {
                Sleep::sleep($delaySeconds);
            }
        }

        throw new RuntimeException("ASCII Box {$boxId} did not finish archiving in time.");
    }

    /**
     * @param  array<string, mixed>  $box
     */
    public function extractIpAddress(array $box): ?string
    {
        $ip = $box['ip'] ?? null;

        return is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            ? $ip
            : null;
    }

    private function executeCommand(string $boxId, string $command): void
    {
        $response = $this->http->post("/boxes/{$boxId}/commands", [
            'command' => $command,
            'timeoutSeconds' => 60,
        ]);
        $response->throw();

        if (! $response->json('success')) {
            $error = trim((string) $response->json('stderr'));
            $error = $error !== '' ? mb_substr($error, -500) : 'unknown command failure';

            throw new RuntimeException("ASCII Box command failed: {$error}");
        }
    }
}
