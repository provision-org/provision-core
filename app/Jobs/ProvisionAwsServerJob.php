<?php

namespace App\Jobs;

use App\Concerns\BuildsServerCallbackUrl;
use App\Enums\HarnessType;
use App\Models\Server;
use App\Services\AwsService;
use App\Services\CloudInitScriptBuilder;
use App\Services\CloudServiceFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

class ProvisionAwsServerJob implements ShouldQueue
{
    use BuildsServerCallbackUrl, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * AWS servers use a single gp3 root volume instead of a separate
     * block-storage volume, so cloud-init "mounts" the root partition
     * (a no-op mkfs thanks to the blkid short-circuit) at the data path.
     * t3.* are Nitro instances, so the root partition is an NVMe device.
     */
    private const ROOT_DEVICE_PATH = '/dev/nvme0n1p1';

    public function __construct(public Server $server) {}

    public function handle(CloudServiceFactory $factory, CloudInitScriptBuilder $scriptBuilder): void
    {
        $team = $this->server->team;

        /** @var AwsService $awsService */
        $awsService = $factory->make($team);

        $this->server->update([
            'daemon_token' => $this->server->daemon_token ?: Str::random(48),
        ]);

        $callbackUrl = $this->buildCallbackUrl();
        $timezone = $team->timezone ?? 'UTC';
        $harnessType = $team->harness_type ?? HarnessType::Hermes;
        $cloudInit = $scriptBuilder->build($callbackUrl, self::ROOT_DEVICE_PATH, $timezone, $harnessType);

        $instance = $awsService->createInstance(
            $team,
            $cloudInit,
            $team->serverType(),
            null,
            "provision-{$this->server->id}",
        );

        // PublicIpAddress may be null until the instance is running —
        // SetupOpenClawOnServerJob fetches it before SSH if missing.
        $ip = $awsService->extractIpAddress($instance);

        $this->server->update([
            'provider_server_id' => (string) $instance['InstanceId'],
            'ipv4_address' => $ip,
        ]);

        // Create a security group for defense-in-depth (UFW also configured
        // in cloud-init). Persist the ID so DestroyTeamJob can release it.
        $securityGroup = $awsService->createSecurityGroup("provision-{$this->server->id}", (string) $instance['InstanceId']);
        $this->server->update([
            'provider_firewall_id' => $securityGroup['id'] ?? null,
        ]);

        $this->server->events()->create([
            'event' => 'provisioning_started',
            'payload' => ['provider_server_id' => $instance['InstanceId'], 'provider' => 'aws'],
        ]);
    }

    public function failed(Throwable $exception): void
    {
        if ($this->server->provider_server_id) {
            /** @var AwsService $awsService */
            $awsService = app(CloudServiceFactory::class)->make($this->server->team);
            $awsService->terminateInstance($this->server->provider_server_id);
        }
    }
}
