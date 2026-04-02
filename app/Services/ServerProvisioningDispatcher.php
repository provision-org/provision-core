<?php

namespace App\Services;

use App\Enums\CloudProvider;
use App\Jobs\ProvisionDigitalOceanServerJob;
use App\Jobs\ProvisionDockerServerJob;
use App\Jobs\ProvisionHetznerServerJob;
use App\Jobs\ProvisionLinodeServerJob;
use App\Models\Server;

class ServerProvisioningDispatcher
{
    public static function dispatch(Server $server): void
    {
        match ($server->cloud_provider) {
            CloudProvider::Docker => ProvisionDockerServerJob::dispatch($server),
            CloudProvider::Hetzner => ProvisionHetznerServerJob::dispatch($server),
            CloudProvider::DigitalOcean => ProvisionDigitalOceanServerJob::dispatch($server),
            CloudProvider::Linode => ProvisionLinodeServerJob::dispatch($server),
        };
    }
}
