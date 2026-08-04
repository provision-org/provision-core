<?php

namespace App\Jobs;

use App\Contracts\CommandExecutor;
use App\Models\ChatMessage;
use App\Models\OpenClawSessionDiscovery;
use App\Models\Server;
use App\Services\HarnessManager;
use App\Services\SshService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use stdClass;
use Throwable;

class EnsureProvisionDaemonCurrentJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 20;

    public int $timeout = 120;

    public int $uniqueFor = 300;

    public function __construct(public Server $server) {}

    public function uniqueId(): string
    {
        return "provisiond-update:{$this->server->id}";
    }

    public function handle(HarnessManager $harnessManager): void
    {
        $lock = Cache::lock($this->executionLockName(), $this->timeout + 60);
        if (! $lock->get()) {
            $this->release(30);

            return;
        }

        try {
            $this->ensureCurrent($harnessManager);
        } finally {
            $lock->release();
        }
    }

    private function ensureCurrent(HarnessManager $harnessManager): void
    {
        $this->server->refresh();
        $desiredVersion = (string) config('provision.provisiond_version', '0.4.0');
        $capabilities = $this->server->daemon_capabilities ?? [];
        $daemonIsCurrent = $this->server->daemon_version === $desiredVersion
            && in_array('chat-relay-v1', $capabilities, true);

        if ($this->serverHasActiveWork()) {
            $this->release(60);

            return;
        }

        $bundle = null;
        if (! $daemonIsCurrent) {
            $bundlePath = base_path('packages/provisiond/bundle/provisiond.mjs');
            $bundle = file_get_contents($bundlePath);
            if ($bundle === false || $bundle === '') {
                throw new RuntimeException('The provisiond release bundle is unavailable.');
            }
        }

        $executor = null;
        $candidate = '/opt/provisiond/provisiond.provision-new.mjs';
        $packageCandidate = '/opt/provisiond/package.provision-new.json';

        try {
            if ($this->server->isDocker()) {
                $executor = $harnessManager->resolveExecutor($this->server);

                if ($this->ensureDockerGatewayAuth($executor)) {
                    $this->restartDockerGateway($executor);
                }
            }

            if ($daemonIsCurrent) {
                return;
            }

            $executor ??= $harnessManager->resolveExecutor($this->server);
            $executor->writeFile($candidate, $bundle);
            $executor->writeFile($packageCandidate, json_encode([
                'version' => $desiredVersion,
            ], JSON_THROW_ON_ERROR));
            $executor->exec('chmod 0755 '.escapeshellarg($candidate));
            $executor->exec('node --check '.escapeshellarg($candidate));
            $executor->exec(
                'mv '.escapeshellarg($candidate).' /opt/provisiond/provisiond.mjs'
                .' && mv '.escapeshellarg($packageCandidate).' /opt/provisiond/package.json'
            );

            if ($this->server->isDocker()) {
                $executor->exec(
                    "pkill -TERM -f '^node /opt/provisiond/provisiond[.]mjs( |$)' 2>/dev/null || true; "
                    ."for attempt in {1..30}; do pgrep -f '^node /opt/provisiond/provisiond[.]mjs( |$)' >/dev/null || break; sleep 1; done; "
                    ."if pgrep -f '^node /opt/provisiond/provisiond[.]mjs( |$)' >/dev/null; then "
                    ."pkill -KILL -f '^node /opt/provisiond/provisiond[.]mjs( |$)' 2>/dev/null || true; "
                    ."for attempt in {1..10}; do pgrep -f '^node /opt/provisiond/provisiond[.]mjs( |$)' >/dev/null || break; sleep 1; done; "
                    .'fi; '
                    ."if pgrep -f '^node /opt/provisiond/provisiond[.]mjs( |$)' >/dev/null; then exit 1; fi; "
                    .'nohup node /opt/provisiond/provisiond.mjs --config /etc/provisiond/config.json '
                    .'>/var/log/provisiond.log 2>&1 </dev/null &'
                );
            } else {
                $executor->exec('systemctl restart provisiond');
            }

            $this->server->forceFill([
                'daemon_version' => null,
                'daemon_capabilities' => null,
            ])->save();
        } finally {
            if ($bundle !== null && $executor instanceof CommandExecutor) {
                $this->cleanupCandidates($executor, [
                    $candidate,
                    $packageCandidate,
                    '/opt/provisiond/provisiond.mjs.provision-new',
                    '/opt/provisiond/package.json.provision-new',
                ]);
            }

            if ($executor instanceof SshService) {
                $executor->disconnect();
            }
        }
    }

    /**
     * @param  list<string>  $candidates
     */
    private function cleanupCandidates(CommandExecutor $executor, array $candidates): void
    {
        try {
            $executor->exec('rm -f '.implode(' ', array_map(escapeshellarg(...), $candidates)));
        } catch (Throwable $exception) {
            Log::warning('Unable to clean staged provisiond update files.', [
                'server_id' => $this->server->id,
                'exception' => $exception::class,
            ]);
        }
    }

    private function executionLockName(): string
    {
        return "provisiond-update-execution:{$this->server->id}";
    }

    private function serverHasActiveWork(): bool
    {
        $hasFreshDaemonRun = ($this->server->last_health_check?->isAfter(now()->subMinutes(2)) ?? false)
            && ($this->server->daemon_active_runs ?? []) !== [];
        if ($hasFreshDaemonRun) {
            return true;
        }

        $hasActiveDashboardChat = ChatMessage::query()
            ->where('delivery_status', 'running')
            ->where('sent_at', '>=', now()->subMinutes(60))
            ->whereHas('conversation.agent', function ($query): void {
                $query->where('server_id', $this->server->id);
            })
            ->exists();
        if ($hasActiveDashboardChat) {
            return true;
        }

        return OpenClawSessionDiscovery::query()
            ->where('server_id', $this->server->id)
            ->where('has_active_run', true)
            ->where('discovered_at', '>=', now()->subMinutes(OpenClawSessionDiscovery::IMPORT_WINDOW_MINUTES))
            ->exists();
    }

    private function ensureDockerGatewayAuth(CommandExecutor $executor): bool
    {
        $path = '/root/.openclaw/openclaw.json';
        $contents = $executor->readFile($path);

        try {
            $config = json_decode($contents, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException('The Docker OpenClaw configuration is not valid JSON.', 0, $exception);
        }

        if (! $config instanceof stdClass) {
            throw new RuntimeException('The Docker OpenClaw configuration must be a JSON object.');
        }

        if (! isset($config->gateway)) {
            $config->gateway = new stdClass;
        }
        if (! $config->gateway instanceof stdClass) {
            throw new RuntimeException('The Docker OpenClaw gateway configuration must be a JSON object.');
        }

        $auth = $config->gateway->auth ?? null;
        $currentMode = $auth instanceof stdClass && is_string($auth->mode ?? null)
            ? $auth->mode
            : null;
        $currentToken = $auth instanceof stdClass && is_string($auth->token ?? null)
            ? trim($auth->token)
            : '';
        $persistedToken = is_string($this->server->gateway_token)
            ? trim($this->server->gateway_token)
            : '';
        $gatewayToken = $persistedToken !== ''
            ? $persistedToken
            : ($currentToken !== '' ? $currentToken : bin2hex(random_bytes(16)));

        if ($persistedToken !== $gatewayToken) {
            $this->server->forceFill(['gateway_token' => $gatewayToken])->saveQuietly();
        }

        if ($currentMode === 'token' && hash_equals($gatewayToken, $currentToken)) {
            return false;
        }

        if (! $auth instanceof stdClass) {
            $auth = new stdClass;
        }
        $auth->mode = 'token';
        $auth->token = $gatewayToken;
        $config->gateway->auth = $auth;

        $encoded = json_encode(
            $config,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
        $candidate = "{$path}.provision-new";
        $executor->writeFile($candidate, $encoded."\n");
        $executor->exec(
            'chmod 0600 '.escapeshellarg($candidate)
            .' && mv '.escapeshellarg($candidate).' '.escapeshellarg($path)
        );

        return true;
    }

    private function restartDockerGateway(CommandExecutor $executor): void
    {
        $executor->exec(
            "pkill -TERM -f '[o]penclaw gateway' 2>/dev/null || true; "
            ."for attempt in {1..30}; do pgrep -f '[o]penclaw gateway' >/dev/null || break; sleep 1; done; "
            ."if pgrep -f '[o]penclaw gateway' >/dev/null; then "
            ."pkill -KILL -f '[o]penclaw gateway' 2>/dev/null || true; "
            ."for attempt in {1..10}; do pgrep -f '[o]penclaw gateway' >/dev/null || break; sleep 1; done; "
            .'fi; '
            ."if pgrep -f '[o]penclaw gateway' >/dev/null; then exit 1; fi; "
            .'export DISPLAY=:99; '
            .'nohup openclaw gateway > /root/.openclaw/logs/gateway.log 2>&1 </dev/null & '
            .'sleep 5; openclaw health 2>/dev/null || true'
        );
    }
}
