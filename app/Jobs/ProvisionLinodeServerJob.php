<?php

namespace App\Jobs;

use App\Concerns\BuildsServerCallbackUrl;
use App\Enums\HarnessType;
use App\Models\Server;
use App\Services\CloudInitScriptBuilder;
use App\Services\CloudServiceFactory;
use App\Services\LinodeService;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Str;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProvisionLinodeServerJob implements ShouldQueue
{
    use BuildsServerCallbackUrl, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public Server $server) {}

    public function handle(CloudServiceFactory $factory, CloudInitScriptBuilder $scriptBuilder): void
    {
        $team = $this->server->team;

        /** @var LinodeService $linodeService */
        $linodeService = $factory->make($team);

        $volumeLabel = 'vol-'.substr($this->server->id, 0, 28);
        $region = config('cloud.regions.us-east.linode', 'us-east');

        $volumeResponse = $linodeService->createVolume($volumeLabel, $team->volumeSize(), $region);
        $volumeId = (string) $volumeResponse['id'];

        $this->server->update([
            'provider_volume_id' => $volumeId,
            'daemon_token' => $this->server->daemon_token ?: Str::random(48),
        ]);

        $callbackUrl = $this->buildCallbackUrl();
        $devicePath = $volumeResponse['filesystem_path'];
        $timezone = $team->timezone ?? 'UTC';
        $harnessType = $team->harness_type ?? HarnessType::Hermes;
        $cloudInit = $scriptBuilder->build($callbackUrl, $devicePath, $timezone, $harnessType);

        $label = 'provision-'.substr($this->server->id, 0, 20);
        $instance = $linodeService->createInstance(
            $label,
            $team->serverType(),
            config('cloud.linode.default_image'),
            $region,
            $cloudInit,
        );

        $ip = $linodeService->extractIpAddress($instance);

        $this->server->update([
            'provider_server_id' => (string) $instance['id'],
            'ipv4_address' => $ip,
        ]);
        if (! empty($instance['_root_password'])) {
            $this->server->forceFill(['root_password' => $instance['_root_password']])->save();
        }

        // Attach volume to the instance
        $linodeService->attachVolume((int) $volumeId, (int) $instance['id']);

        // Create firewall for defense-in-depth (UFW also configured in cloud-init)
        $linodeService->createFirewall('fw-'.substr($this->server->id, 0, 29), (int) $instance['id']);

        $this->server->events()->create([
            'event' => 'provisioning_started',
            'payload' => ['provider_server_id' => $instance['id'], 'provider' => 'linode'],
        ]);
    }

    public function failed(Throwable $exception): void
    {
        if ($this->server->provider_volume_id && ! $this->server->provider_server_id) {
            app(CloudServiceFactory::class)->make($this->server->team)->deleteVolume($this->server->provider_volume_id);
        }
    }
}
