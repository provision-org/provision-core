<?php

use App\Enums\CloudProvider;
use App\Jobs\ProvisionDigitalOceanServerJob;
use App\Jobs\ProvisionHetznerServerJob;
use App\Jobs\ProvisionLinodeServerJob;
use App\Models\Server;
use App\Services\ServerProvisioningDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

it('dispatches hetzner job for hetzner servers', function () {
    Bus::fake();

    $server = Server::factory()->create(['cloud_provider' => CloudProvider::Hetzner]);

    ServerProvisioningDispatcher::dispatch($server);

    Bus::assertDispatched(ProvisionHetznerServerJob::class);
    Bus::assertNotDispatched(ProvisionDigitalOceanServerJob::class);
});

it('dispatches digitalocean job for digitalocean servers', function () {
    Bus::fake();

    $server = Server::factory()->digitalOcean()->create();

    ServerProvisioningDispatcher::dispatch($server);

    Bus::assertDispatched(ProvisionDigitalOceanServerJob::class);
    Bus::assertNotDispatched(ProvisionHetznerServerJob::class);
});

it('dispatches linode job for linode servers', function () {
    Bus::fake();

    $server = Server::factory()->create(['cloud_provider' => CloudProvider::Linode]);

    ServerProvisioningDispatcher::dispatch($server);

    Bus::assertDispatched(ProvisionLinodeServerJob::class);
    Bus::assertNotDispatched(ProvisionDigitalOceanServerJob::class);
    Bus::assertNotDispatched(ProvisionHetznerServerJob::class);
});
