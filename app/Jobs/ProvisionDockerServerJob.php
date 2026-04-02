<?php

namespace App\Jobs;

use App\Enums\HarnessType;
use App\Enums\ServerStatus;
use App\Models\Server;
use App\Services\DockerExecutor;
use App\Services\OpenClawDefaultsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProvisionDockerServerJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public function __construct(public Server $server) {}

    public function handle(DockerExecutor $executor, OpenClawDefaultsService $defaultsService): void
    {
        $this->server->update([
            'status' => ServerStatus::SetupComplete,
            'ipv4_address' => '127.0.0.1',
            'provisioned_at' => now(),
        ]);

        $team = $this->server->team;
        $isOpenClaw = ($team->harness_type ?? HarnessType::OpenClaw) === HarnessType::OpenClaw;

        if ($isOpenClaw) {
            $this->setupOpenClaw($executor, $defaultsService);
        }

        $this->server->update(['status' => ServerStatus::Running]);
    }

    private function setupOpenClaw(DockerExecutor $executor, OpenClawDefaultsService $defaultsService): void
    {
        // Ensure directories exist
        $executor->exec('mkdir -p /root/.openclaw/agents /root/.openclaw/logs /root/.openclaw/cron');

        // Write openclaw.json — same config as cloud servers
        $config = $this->buildOpenClawConfig($defaultsService);
        $executor->writeFile(
            '/root/.openclaw/openclaw.json',
            json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        // Configure gateway for container (no systemd)
        $executor->exec('openclaw config set gateway.mode local 2>/dev/null || true');

        // Disable device pairing (auto-approve all senders)
        $executor->exec('openclaw config set plugins.entries.device-pair.enabled false 2>/dev/null || true');

        // Start gateway in background
        $executor->exec(
            'export DISPLAY=:99 && nohup openclaw gateway > /root/.openclaw/logs/gateway.log 2>&1 & '
            .'sleep 5 && openclaw health 2>/dev/null || true'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildOpenClawConfig(OpenClawDefaultsService $defaultsService): array
    {
        $defaults = $defaultsService->buildDefaults($this->server);

        return [
            'bindings' => [],
            'agents' => [
                'defaults' => array_replace_recursive([
                    'sandbox' => ['mode' => 'off'],
                    'typingMode' => 'thinking',
                    'heartbeat' => ['lightContext' => true],
                ], $defaults),
            ],
            'browser' => [
                'enabled' => true,
                'headless' => false,
                'noSandbox' => true,
                'executablePath' => config('openclaw.browser_executable_path'),
                'snapshotDefaults' => ['mode' => 'efficient'],
                'extraArgs' => ['--ozone-override-screen-size=1440,900', '--window-size=1440,900'],
            ],
            'channels' => (object) [],
            'gateway' => [
                'mode' => 'local',
                'bind' => config('openclaw.gateway_bind'),
            ],
            'logging' => [
                'redactSensitive' => 'tools',
            ],
            'messages' => [
                'queue' => ['mode' => 'collect', 'debounceMs' => 2000],
                'inbound' => ['debounceMs' => 2000],
            ],
            'plugins' => [
                'entries' => [
                    'device-pair' => ['enabled' => false],
                ],
            ],
            'session' => [
                'dmScope' => 'per-channel-peer',
                'reset' => ['mode' => 'idle', 'idleMinutes' => 120],
                'maintenance' => ['pruneAfter' => '14d', 'maxEntries' => 500, 'maxDiskBytes' => '500mb'],
            ],
            'skills' => [
                'load' => ['watch' => true],
            ],
            'tools' => [
                'deny' => ['canvas', 'nodes', 'gateway', 'config', 'system', 'telegram', 'whatsapp', 'discord', 'irc', 'googlechat', 'slack', 'signal', 'imessage'],
                'loopDetection' => ['enabled' => true, 'historySize' => 30, 'warningThreshold' => 10, 'criticalThreshold' => 20],
            ],
        ];
    }
}
