<?php

namespace App\Jobs;

use App\Concerns\BuildsServerCallbackUrl;
use App\Enums\HarnessType;
use App\Models\Server;
use App\Services\CloudInitScriptBuilder;
use App\Services\CloudServiceFactory;
use App\Services\HetznerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProvisionHetznerServerJob implements ShouldQueue
{
    use BuildsServerCallbackUrl, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public Server $server) {}

    public function handle(CloudServiceFactory $factory, CloudInitScriptBuilder $scriptBuilder): void
    {
        $team = $this->server->team;

        /** @var HetznerService $hetznerService */
        $hetznerService = $factory->make($team);

        $volumeResponse = $hetznerService->createVolume(
            "provision-{$this->server->team_id}-{$this->server->id}",
            $team->volumeSize(),
        );

        $volumeId = (string) $volumeResponse['volume']['id'];

        $this->server->update(['provider_volume_id' => $volumeId]);

        $callbackUrl = $this->buildCallbackUrl();
        $devicePath = "/dev/disk/by-id/scsi-0HC_Volume_{$volumeId}";
        $timezone = $team->timezone ?? 'UTC';
        $harnessType = $team->harness_type ?? HarnessType::Hermes;
        $cloudInit = $scriptBuilder->build($callbackUrl, $devicePath, $timezone, $harnessType);

        $response = $hetznerService->createServer(
            $team,
            $cloudInit,
            [(int) $volumeId],
            $team->serverType(),
        );

        $this->server->update([
            'provider_server_id' => (string) $response['server']['id'],
            'ipv4_address' => $response['server']['public_net']['ipv4']['ip'] ?? null,
        ]);

        $this->server->events()->create([
            'event' => 'provisioning_started',
            'payload' => ['provider_server_id' => $response['server']['id']],
        ]);
    }

    public function failed(Throwable $exception): void
    {
        if ($this->server->provider_volume_id && ! $this->server->provider_server_id) {
            app(CloudServiceFactory::class)->make($this->server->team)->deleteVolume($this->server->provider_volume_id);
        }
    }
}
