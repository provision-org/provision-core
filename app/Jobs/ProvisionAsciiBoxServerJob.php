<?php

namespace App\Jobs;

use App\Concerns\BuildsServerCallbackUrl;
use App\Enums\HarnessType;
use App\Enums\ServerStatus;
use App\Models\Server;
use App\Services\AsciiBoxService;
use App\Services\CloudInitScriptBuilder;
use App\Services\CloudServiceFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ProvisionAsciiBoxServerJob implements ShouldBeUnique, ShouldQueue
{
    use BuildsServerCallbackUrl, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 300;

    public int $uniqueFor = 600;

    public function __construct(public Server $server) {}

    public function uniqueId(): string
    {
        return (string) $this->server->id;
    }

    public function handle(CloudServiceFactory $factory, CloudInitScriptBuilder $scriptBuilder): void
    {
        $team = $this->server->team;
        $publicKey = $this->publicSshKey();

        /** @var AsciiBoxService $ascii */
        $ascii = $factory->make($team);

        $this->server->update([
            'daemon_token' => $this->server->daemon_token ?: Str::random(48),
        ]);

        if (! $this->server->provider_server_id) {
            $box = $ascii->createBox([
                'PROVISION_SERVER_ID' => (string) $this->server->id,
                'PROVISION_TEAM_ID' => (string) $team->id,
            ]);
            $boxId = $box['id'] ?? null;

            if (! is_string($boxId) || $boxId === '') {
                throw new RuntimeException('ASCII Box create response did not include a box id.');
            }

            // Persist immediately: a retry after polling/bootstrap fails must
            // resume this billable Box rather than creating an orphan.
            $this->server->update(['provider_server_id' => $boxId]);
        }

        $boxId = (string) $this->server->provider_server_id;
        $box = $ascii->waitUntilReady($boxId);
        $ipAddress = $ascii->extractIpAddress($box);

        if (! $ipAddress) {
            throw new RuntimeException("ASCII Box {$boxId} is ready but has no valid IPv4 address.");
        }

        $this->server->update(['ipv4_address' => $ipAddress]);

        $ascii->configureProvisionSsh($boxId, $publicKey);

        $callbackUrl = $this->buildCallbackUrl();
        $timezone = $team->timezone ?? 'UTC';
        $harnessType = $team->harness_type ?? HarnessType::Hermes;
        $bootstrap = $scriptBuilder->buildForRootFilesystem($callbackUrl, $timezone, $harnessType);

        $ascii->startBootstrap($boxId, $bootstrap);

        $this->server->events()->firstOrCreate(
            ['event' => 'provisioning_started'],
            ['payload' => ['provider_server_id' => $boxId, 'provider' => 'ascii']],
        );
    }

    public function failed(Throwable $exception): void
    {
        if ($this->server->provider_server_id) {
            try {
                /** @var AsciiBoxService $ascii */
                $ascii = app(CloudServiceFactory::class)->make($this->server->team);
                $ascii->archiveBox($this->server->provider_server_id);
            } catch (Throwable $cleanupException) {
                Log::warning("Could not archive failed ASCII Box {$this->server->provider_server_id}: {$cleanupException->getMessage()}");
            }
        }

        $this->server->update(['status' => ServerStatus::Error]);
        $this->server->events()->create([
            'event' => 'provisioning_error',
            'payload' => ['error_message' => $exception->getMessage()],
        ]);
    }

    private function publicSshKey(): string
    {
        $path = config('cloud.ascii.ssh_public_key_path');

        if (! is_string($path) || $path === '' || ! is_file($path)) {
            throw new RuntimeException('ASCII Box requires SSH_PUBLIC_KEY_PATH or SSH_PRIVATE_KEY_PATH with a matching .pub file.');
        }

        $publicKey = trim((string) file_get_contents($path));

        if ($publicKey === '') {
            throw new RuntimeException('ASCII Box SSH public key file is empty.');
        }

        return $publicKey;
    }
}
