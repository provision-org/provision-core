<?php

use App\Enums\ServerStatus;
use App\Models\Agent;
use App\Models\GatewayConfig;
use App\Models\Server;
use App\Models\ServerEvent;
use App\Models\Team;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('belongs to a team', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);

    expect($server->team->id)->toBe($team->id);
});

it('has many events', function () {
    $server = Server::factory()->create();
    ServerEvent::factory()->count(3)->create(['server_id' => $server->id]);

    expect($server->events)->toHaveCount(3);
});

it('has many agents', function () {
    $server = Server::factory()->running()->create();
    Agent::factory()->count(2)->create(['server_id' => $server->id]);

    expect($server->agents)->toHaveCount(2);
});

it('has one gateway config', function () {
    $server = Server::factory()->create();
    $config = GatewayConfig::factory()->create([
        'server_id' => $server->id,
        'team_id' => $server->team_id,
    ]);

    expect($server->gatewayConfig->id)->toBe($config->id);
});

it('defaults to provisioning status', function () {
    $server = Server::factory()->create();

    expect($server->status)->toBe(ServerStatus::Provisioning);
});

it('has a running factory state', function () {
    $server = Server::factory()->running()->create();

    expect($server->status)->toBe(ServerStatus::Running)
        ->and($server->provider_server_id)->not->toBeNull()
        ->and($server->ipv4_address)->not->toBeNull()
        ->and($server->provisioned_at)->not->toBeNull()
        ->and($server->last_health_check)->not->toBeNull();
});

it('has an error factory state', function () {
    $server = Server::factory()->error()->create();

    expect($server->status)->toBe(ServerStatus::Error);
});

it('casts status to ServerStatus enum', function () {
    $server = Server::factory()->create();

    expect($server->status)->toBeInstanceOf(ServerStatus::class);
});

it('encrypts the gateway_token', function () {
    $server = Server::factory()->create(['gateway_token' => 'secret-token']);

    expect($server->gateway_token)->toBe('secret-token');

    // Verify it's stored encrypted in the database
    $raw = DB::table('servers')
        ->where('id', $server->id)
        ->value('gateway_token');

    expect($raw)->not->toBe('secret-token');
});

it('casts provisioned_at and last_health_check to datetime', function () {
    $server = Server::factory()->running()->create();

    expect($server->provisioned_at)->toBeInstanceOf(CarbonInterface::class)
        ->and($server->last_health_check)->toBeInstanceOf(CarbonInterface::class);
});
