<?php

namespace App\Jobs;

use App\Concerns\BuildsServerCallbackUrl;
use App\Models\Server;
use App\Services\CloudInitScriptBuilder;
use App\Services\CloudServiceFactory;
use App\Services\DigitalOceanService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProvisionDigitalOceanServerJob implements ShouldQueue
{
    use BuildsServerCallbackUrl, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public Server $server) {}

    public function handle(CloudServiceFactory $factory, CloudInitScriptBuilder $scriptBuilder): void
    {
        $team = $this->server->team;

        /** @var DigitalOceanService $doService */
        $doService = $factory->make($team);

        $volumeName = "provision-{$this->server->team_id}-{$this->server->id}";
        $region = config('cloud.regions.us-east.digitalocean', 'nyc1');

        $volumeResponse = $doService->createVolume($volumeName, $team->volumeSize(), $region);
        $volumeId = (string) $volumeResponse['volume']['id'];

        $this->server->update(['provider_volume_id' => $volumeId]);

        $callbackUrl = $this->buildCallbackUrl();
        $devicePath = "/dev/disk/by-id/scsi-0DO_Volume_{$volumeName}";
        $timezone = $team->timezone ?? 'UTC';
        $harnessType = $team->harness_type ?? \App\Enums\HarnessType::Hermes;
        $cloudInit = $scriptBuilder->build($callbackUrl, $devicePath, $timezone, $harnessType);

        $response = $doService->createDroplet(
            $team,
            $cloudInit,
            [$volumeId],
            $team->serverType(),
            $region,
        );

        $droplet = $response['droplet'];
        $ip = $doService->extractIpAddress($droplet);

        $this->server->update([
            'provider_server_id' => (string) $droplet['id'],
            'ipv4_address' => $ip,
        ]);

        // Create DO firewall for defense-in-depth (UFW also configured in cloud-init)
        $doService->createFirewall("provision-{$this->server->id}", (int) $droplet['id']);

        $this->server->events()->create([
            'event' => 'provisioning_started',
            'payload' => ['provider_server_id' => $droplet['id'], 'provider' => 'digitalocean'],
        ]);
    }

    public function failed(Throwable $exception): void
    {
        if ($this->server->provider_volume_id && ! $this->server->provider_server_id) {
            app(CloudServiceFactory::class)->make($this->server->team)->deleteVolume($this->server->provider_volume_id);
        }
    }
}
