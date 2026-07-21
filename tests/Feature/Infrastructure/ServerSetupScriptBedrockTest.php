<?php

use App\Enums\HarnessType;
use App\Models\Server;
use App\Models\Team;
use App\Models\TeamApiKey;
use App\Services\Scripts\ServerSetupScriptService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function awsOpenClawServerInRegion(string $region): Server
{
    $team = Team::factory()->aws()->create(['harness_type' => HarnessType::OpenClaw]);
    TeamApiKey::factory()->awsCloud()->create([
        'team_id' => $team->id,
        'api_key' => json_encode([
            'key_id' => 'AKIAEXAMPLEEXAMPLE',
            'secret' => 'secret',
            'region' => $region,
        ]),
    ]);

    return Server::factory()->aws()->create([
        'team_id' => $team->id,
        'ipv4_address' => '203.0.113.42',
    ]);
}

test('setup script installs both bedrock plugins and sets AWS_REGION for a mantle-region AWS team', function () {
    $script = app(ServerSetupScriptService::class)->generateScript(awsOpenClawServerInRegion('us-east-1'));

    // Both providers installed: classic (bedrock:) + the separate, non-bundled
    // Mantle plugin (mantle:) — us-east-1 is a Mantle region.
    expect($script)->toContain('openclaw plugins install @openclaw/amazon-bedrock-provider')
        ->and($script)->toContain('openclaw plugins install @openclaw/amazon-bedrock-mantle-provider');

    // The gateway daemon needs AWS_REGION in its own env or the Mantle SigV4
    // bearer-token mint fails and discovery silently skips every model.
    expect($script)->toContain('Environment=AWS_REGION=us-east-1')
        ->and($script)->toContain('Environment=AWS_DEFAULT_REGION=us-east-1');
});

test('setup script skips the mantle plugin outside mantle regions but still sets AWS_REGION', function () {
    $script = app(ServerSetupScriptService::class)->generateScript(awsOpenClawServerInRegion('ca-central-1'));

    expect($script)->toContain('openclaw plugins install @openclaw/amazon-bedrock-provider')
        ->and($script)->not->toContain('amazon-bedrock-mantle-provider')
        ->and($script)->toContain('Environment=AWS_REGION=ca-central-1');
});

test('setup script does not set AWS_REGION or install bedrock plugins for a non-AWS team', function () {
    $team = Team::factory()->create(['cloud_provider' => 'digitalocean', 'harness_type' => HarnessType::OpenClaw]);
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'ipv4_address' => '203.0.113.42',
    ]);

    $script = app(ServerSetupScriptService::class)->generateScript($server);

    expect($script)->not->toContain('amazon-bedrock')
        ->and($script)->not->toContain('AWS_REGION');
});

test('openclaw setup keeps the gateway on loopback behind a WebSocket-only TLS proxy', function () {
    $team = Team::factory()->create([
        'cloud_provider' => 'digitalocean',
        'harness_type' => HarnessType::OpenClaw,
    ]);
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'ipv4_address' => '203.0.113.42',
    ]);

    $script = app(ServerSetupScriptService::class)->generateScript($server);

    expect($script)->toContain('gateway.203-0-113-42.sslip.io {')
        ->toContain("`header({'Connection':'*Upgrade*','Upgrade':'websocket'}) || header({':protocol':'websocket'})`")
        ->toContain('reverse_proxy 127.0.0.1:18789')
        ->toContain('respond 404')
        ->toContain('chmod 0644 /etc/caddy/Caddyfile')
        ->toContain('"bind": "loopback"')
        ->toContain('"trustedProxies": [')
        ->toContain('"127.0.0.1"')
        ->toContain('"::1"')
        ->toContain('"controlUi": {')
        ->toContain('"allowedOrigins": [')
        ->toContain('"https://gateway.203-0-113-42.sslip.io"')
        ->not->toContain('0.0.0.0:18789');
});
