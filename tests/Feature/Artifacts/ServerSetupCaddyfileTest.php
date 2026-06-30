<?php

use App\Models\Server;
use App\Services\Scripts\ServerSetupScriptService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the server setup script configures on-demand TLS and the artifact sites import', function () {
    config(['app.url' => 'https://app.provision.test']);

    $server = Server::factory()->running()->create(['ipv4_address' => '203.0.113.9']);

    $script = app(ServerSetupScriptService::class)->generateScript($server);

    expect($script)
        ->toContain('mkdir -p /etc/caddy/conf.d /etc/caddy/sites')
        ->toContain('on_demand_tls')
        ->toContain('ask https://app.provision.test/api/caddy/ask')
        ->toContain('import /etc/caddy/sites/*.caddy')
        // The existing sslip.io host block + conf.d import is preserved.
        ->toContain('import /etc/caddy/conf.d/*.caddy');
});
