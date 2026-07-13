<?php

use App\Enums\HarnessType;
use App\Models\Agent;
use App\Models\Server;
use App\Models\Team;
use App\Services\AgentInstallScriptService;
use App\Services\Harness\HermesDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('browserProfileName prefers the frozen column over the agent name', function () {
    $agent = Agent::factory()->make([
        'name' => 'Kate Wilson',
        'browser_profile_name' => 'agent-kate-w',
    ]);

    expect(AgentInstallScriptService::browserProfileName($agent))->toBe('agent-kate-w');
});

test('browserProfileName falls back to the name when nothing is frozen', function () {
    $agent = Agent::factory()->make([
        'name' => 'Kate Wilson',
        'browser_profile_name' => null,
    ]);

    expect(AgentInstallScriptService::browserProfileName($agent))->toBe('agent-kate-wilson');
});

test('install freezes the browser profile name and a later rename never changes it', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->deploying()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'harness_agent_id' => 'agent-abc',
        'name' => 'Kate W',
    ]);

    $expiresAt = now()->addMinutes(10)->timestamp;
    $signature = hash_hmac('sha256', "install|{$agent->id}|{$expiresAt}", config('app.key'));

    $this->get("/api/agents/{$agent->id}/install-script?expires_at={$expiresAt}&signature={$signature}")
        ->assertOk();

    expect($agent->fresh()->browser_profile_name)->toBe('agent-kate-w');

    // The exact break we're fixing: renaming used to change the computed profile
    // name and orphan the server-side Caddy route. The frozen value must hold.
    $agent->update(['name' => 'Kate Wilson']);

    expect(AgentInstallScriptService::browserProfileName($agent->fresh()))->toBe('agent-kate-w');
});

test('hermes default profile name uses the hermes prefix', function () {
    $agent = Agent::factory()->make([
        'name' => 'Scout',
        'harness_type' => HarnessType::Hermes,
        'browser_profile_name' => null,
    ]);

    expect(HermesDriver::browserProfileName($agent))->toBe('hermes-scout');
});
