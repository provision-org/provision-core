<?php

use App\Enums\HarnessType;
use App\Models\Server;
use App\Models\Team;
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
        ->toContain('import /etc/caddy/conf.d/*.caddy')
        ->not->toContain('gateway.203-0-113-9.sslip.io {');
});

test('the OpenClaw server setup preserves artifacts and the mobile gateway', function () {
    config(['app.url' => 'https://app.provision.test']);

    $team = Team::factory()->create(['harness_type' => HarnessType::OpenClaw]);
    $server = Server::factory()->running()->create([
        'team_id' => $team->id,
        'ipv4_address' => '203.0.113.9',
    ]);

    $script = app(ServerSetupScriptService::class)->generateScript($server);

    expect($script)
        ->toContain('ask https://app.provision.test/api/caddy/ask')
        ->toContain('import /etc/caddy/sites/*.caddy')
        ->toContain('gateway.203-0-113-9.sslip.io {')
        ->toContain('reverse_proxy 127.0.0.1:18789');
});
