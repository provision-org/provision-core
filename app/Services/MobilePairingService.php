<?php

namespace App\Services;

use App\Contracts\CommandExecutor;
use App\Exceptions\MobilePairingFailedException;
use App\Exceptions\MobilePairingUnavailableException;
use App\Models\Agent;
use App\Models\MobilePairingHandoff;
use App\Models\Server;
use App\Models\User;
use App\Support\OpenClawGatewayEndpoint;
use BaconQrCode\Renderer\Color\Rgb;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\Fill;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\DB;
use JsonException;
use Throwable;

class MobilePairingService
{
    public function __construct(
        private readonly HarnessManager $harnessManager,
        private readonly OpenClawGatewayEndpoint $gatewayEndpoint,
    ) {}

    /**
     * @return array{handoffId: string, qrSvg: string, pairingCode: string, expiresAt: string, statusUrl: string}
     */
    public function createHandoff(Agent $agent, User $user): array
    {
        $server = $agent->server;

        if ($server === null) {
            throw new MobilePairingFailedException;
        }

        if ($server->team_id !== $agent->team_id || $server->isDocker()) {
            throw new MobilePairingFailedException;
        }

        $exchangeUrl = $this->exchangeUrl();

        try {
            $executor = $this->harnessManager->resolveExecutor($server);
            $this->ensureCompatibleVersion($server, $executor);
            $this->gatewayEndpoint->ensureConfigured($server, $executor);
        } catch (MobilePairingUnavailableException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new MobilePairingFailedException;
        }

        $rawToken = bin2hex(random_bytes(32));
        $expiresAt = now()->addSeconds((int) config('openclaw.mobile_pairing.handoff_ttl_seconds', 300));

        $handoff = DB::transaction(function () use ($agent, $user, $server, $rawToken, $expiresAt): MobilePairingHandoff {
            $lockedAgent = Agent::query()
                ->whereKey($agent->id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedServer = Server::query()
                ->whereKey($server->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedAgent->team_id !== $lockedServer->team_id
                || $lockedAgent->server_id !== $lockedServer->id
                || $lockedServer->isDocker()) {
                throw new MobilePairingFailedException;
            }

            MobilePairingHandoff::query()
                ->where('agent_id', $agent->id)
                ->where('created_by_user_id', $user->id)
                ->whereNull('consumed_at')
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->update(['revoked_at' => now(), 'updated_at' => now()]);

            return MobilePairingHandoff::query()->create([
                'team_id' => $agent->team_id,
                'agent_id' => $agent->id,
                'server_id' => $server->id,
                'created_by_user_id' => $user->id,
                'token_hash' => hash('sha256', $rawToken),
                'expires_at' => $expiresAt,
            ]);
        });

        $envelope = [
            'v' => 1,
            'type' => 'provision-agent',
            'agentId' => $agent->harness_agent_id ?: $agent->id,
            'agentName' => $agent->name,
            'agentEmoji' => $agent->emoji,
            'exchange' => [
                'url' => $exchangeUrl,
                'token' => $rawToken,
            ],
        ];

        try {
            $json = json_encode($envelope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            $handoff->update(['revoked_at' => now()]);

            throw new MobilePairingFailedException;
        }

        $pairingCode = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
        $deepLink = 'provision://pair?payload='.rawurlencode($pairingCode);

        return [
            'handoffId' => $handoff->id,
            'qrSvg' => $this->qrSvg($deepLink),
            'pairingCode' => $pairingCode,
            'expiresAt' => $handoff->expires_at->toIso8601String(),
            'statusUrl' => route('agents.provision-app.handoffs.show', [$agent, $handoff], false),
        ];
    }

    public function exchange(string $rawToken): string
    {
        $handoff = DB::transaction(function () use ($rawToken): MobilePairingHandoff {
            $handoff = MobilePairingHandoff::query()
                ->where('token_hash', hash('sha256', $rawToken))
                ->lockForUpdate()
                ->first();

            if ($handoff === null
                || $handoff->expires_at->isPast()
                || $handoff->consumed_at !== null
                || $handoff->revoked_at !== null
                || $handoff->failed_at !== null
                || $handoff->completed_at !== null) {
                throw new MobilePairingUnavailableException;
            }

            $handoff->update(['consumed_at' => now()]);

            return $handoff;
        });

        try {
            $server = $handoff->server()->firstOrFail();
            $agent = $handoff->agent()->firstOrFail();

            if ($server->team_id !== $handoff->team_id
                || $agent->team_id !== $handoff->team_id
                || $agent->server_id !== $server->id
                || $server->isDocker()) {
                throw new MobilePairingFailedException;
            }

            $executor = $this->harnessManager->resolveExecutor($server);
            $gatewayUrl = $this->gatewayEndpoint->wssUrl($server);
            $output = $executor->exec('openclaw qr --json --url '.escapeshellarg($gatewayUrl));
            $setupCode = $this->setupCodeFromOutput($output);

            $handoff->update(['completed_at' => now()]);

            return $setupCode;
        } catch (Throwable) {
            $handoff->update([
                'failed_at' => now(),
                'failure_code' => 'gateway_qr_failed',
            ]);

            throw new MobilePairingFailedException;
        }
    }

    private function exchangeUrl(): string
    {
        $url = config('openclaw.mobile_pairing.exchange_url')
            ?: route('api.mobile.pairing.exchange');

        if (! is_string($url) || parse_url($url, PHP_URL_SCHEME) !== 'https') {
            throw new MobilePairingFailedException;
        }

        return $url;
    }

    private function ensureCompatibleVersion(Server $server, CommandExecutor $executor): void
    {
        $required = (string) config('provision.openclaw_version');
        $installed = $this->parseVersion($server->openclaw_version);

        if ($installed === null || version_compare($installed, $required, '<')) {
            try {
                $installed = $this->parseVersion($executor->exec('openclaw --version 2>/dev/null'));
            } catch (Throwable) {
                $installed = null;
            }

            if ($installed !== null && $installed !== $server->openclaw_version) {
                $server->forceFill(['openclaw_version' => $installed])->save();
            }
        }

        if ($installed === null || version_compare($installed, $required, '<')) {
            throw new MobilePairingUnavailableException(
                "Update this agent to OpenClaw {$required} or newer before pairing the Provision App.",
            );
        }
    }

    private function parseVersion(?string $value): ?string
    {
        if (! is_string($value)
            || preg_match('/(\d{4}\.\d+\.\d+(?:[-.][a-z0-9.]+)?)/i', $value, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private function qrSvg(string $contents): string
    {
        $svg = (new Writer(
            new ImageRenderer(
                new RendererStyle(
                    320,
                    2,
                    null,
                    null,
                    Fill::uniformColor(new Rgb(255, 255, 255), new Rgb(15, 23, 42)),
                ),
                new SvgImageBackEnd,
            ),
        ))->writeString($contents);

        return trim(substr($svg, strpos($svg, "\n") + 1));
    }

    private function setupCodeFromOutput(string $output): string
    {
        $decoded = json_decode(trim($output), true);

        if (! is_array($decoded)) {
            throw new MobilePairingFailedException;
        }

        $setupCode = $decoded['setupCode'] ?? null;

        if (! is_string($setupCode) || strlen($setupCode) < 24 || strlen($setupCode) > 8192) {
            throw new MobilePairingFailedException;
        }

        return $setupCode;
    }
}
