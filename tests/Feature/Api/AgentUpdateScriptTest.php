<?php

use App\Enums\AgentStatus;
use App\Enums\HarnessType;
use App\Models\Agent;
use App\Models\Server;
use App\Models\Team;
use App\Services\Scripts\AgentUpdateScriptService;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('update script endpoint returns openclaw script with valid signature', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'harness_agent_id' => 'agent-abc',
        'harness_type' => HarnessType::OpenClaw,
        'name' => 'Atlas',
        'status' => AgentStatus::Active,
        'system_prompt' => 'You are helpful.',
        'soul' => 'A helpful agent.',
    ]);

    $expiresAt = now()->addMinutes(10)->timestamp;
    $signature = hash_hmac('sha256', "agent-update|{$agent->id}|{$expiresAt}", config('app.key'));

    $response = $this->get("/api/agents/{$agent->id}/update-script?expires_at={$expiresAt}&signature={$signature}");

    $response->assertOk();
    $response->assertHeader('Content-Type', 'text/plain; charset=UTF-8');

    $script = $response->getContent();
    expect($script)->toContain('#!/bin/bash')
        ->toContain('agent-abc')
        ->toContain('openclaw.json')
        ->toContain('SOUL.md')
        ->toContain('AGENTS.md')
        ->toContain('TOOLS.md')
        ->toContain('systemctl --user restart openclaw-gateway')
        ->toContain('openclaw health');
});

test('update script endpoint returns hermes script for hermes agents', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'harness_agent_id' => 'hermes-abc',
        'harness_type' => HarnessType::Hermes,
        'name' => 'Mercury',
        'status' => AgentStatus::Active,
        'model_primary' => 'claude-sonnet-4-20250514',
    ]);

    $expiresAt = now()->addMinutes(10)->timestamp;
    $signature = hash_hmac('sha256', "agent-update|{$agent->id}|{$expiresAt}", config('app.key'));

    $response = $this->get("/api/agents/{$agent->id}/update-script?expires_at={$expiresAt}&signature={$signature}");

    $response->assertOk();

    $script = $response->getContent();
    expect($script)->toContain('#!/bin/bash')
        ->toContain('hermes-abc')
        ->toContain('SOUL.md')
        ->toContain('config.yaml')
        ->toContain('BROWSER_CDP_URL')
        ->toContain('hermes gateway restart');
});

test('update script endpoint rejects invalid signature', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
    ]);

    $expiresAt = now()->addMinutes(10)->timestamp;

    $response = $this->get("/api/agents/{$agent->id}/update-script?expires_at={$expiresAt}&signature=invalid-sig");

    $response->assertForbidden();
});

test('update script endpoint rejects expired signature', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
    ]);

    $expiresAt = now()->subMinute()->timestamp;
    $signature = hash_hmac('sha256', "agent-update|{$agent->id}|{$expiresAt}", config('app.key'));

    $response = $this->get("/api/agents/{$agent->id}/update-script?expires_at={$expiresAt}&signature={$signature}");

    $response->assertForbidden();
});

test('update callback marks agent as synced', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'status' => AgentStatus::Active,
        'is_syncing' => true,
    ]);

    $expiresAt = now()->addMinutes(30)->timestamp;
    $signature = hash_hmac('sha256', "agent-update-callback|{$agent->id}|{$expiresAt}", config('app.key'));

    $response = $this->postJson("/api/webhooks/agent-update?agent_id={$agent->id}&expires_at={$expiresAt}&signature={$signature}", [
        'agent_id' => $agent->id,
        'status' => 'updated',
        'expires_at' => $expiresAt,
        'signature' => $signature,
    ]);

    $response->assertOk();
    $agent->refresh();
    expect($agent->is_syncing)->toBeFalse();
    expect($agent->last_synced_at)->not->toBeNull();
});

test('update callback transitions deploying agent to active', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->deploying()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'is_syncing' => true,
    ]);

    $expiresAt = now()->addMinutes(30)->timestamp;
    $signature = hash_hmac('sha256', "agent-update-callback|{$agent->id}|{$expiresAt}", config('app.key'));

    $response = $this->postJson("/api/webhooks/agent-update?agent_id={$agent->id}&expires_at={$expiresAt}&signature={$signature}", [
        'agent_id' => $agent->id,
        'status' => 'updated',
        'expires_at' => $expiresAt,
        'signature' => $signature,
    ]);

    $response->assertOk();
    expect($agent->fresh()->status)->toBe(AgentStatus::Active);
});

test('update error callback clears syncing flag', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'status' => AgentStatus::Active,
        'is_syncing' => true,
    ]);

    $expiresAt = now()->addMinutes(30)->timestamp;
    $signature = hash_hmac('sha256', "agent-update-callback|{$agent->id}|{$expiresAt}", config('app.key'));

    $response = $this->postJson("/api/webhooks/agent-update?agent_id={$agent->id}&expires_at={$expiresAt}&signature={$signature}", [
        'agent_id' => $agent->id,
        'status' => 'error',
        'error_message' => 'Update failed at line 42',
        'expires_at' => $expiresAt,
        'signature' => $signature,
    ]);

    $response->assertOk();
    $agent->refresh();
    expect($agent->is_syncing)->toBeFalse();
    expect($agent->status)->toBe(AgentStatus::Active); // Status should NOT change on error
});

test('update callback rejects invalid signature', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
    ]);

    $expiresAt = now()->addMinutes(30)->timestamp;

    $response = $this->postJson("/api/webhooks/agent-update?agent_id={$agent->id}&expires_at={$expiresAt}&signature=bad-sig", [
        'agent_id' => $agent->id,
        'status' => 'updated',
        'expires_at' => $expiresAt,
        'signature' => 'bad-sig',
    ]);

    $response->assertForbidden();
});

test('openclaw update script includes channel config for all agents on server', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);

    // Agent with Telegram
    $agent1 = Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'harness_agent_id' => 'agent-one',
        'harness_type' => HarnessType::OpenClaw,
        'name' => 'Alpha',
        'status' => AgentStatus::Active,
    ]);

    $agent2 = Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'harness_agent_id' => 'agent-two',
        'harness_type' => HarnessType::OpenClaw,
        'name' => 'Beta',
        'status' => AgentStatus::Active,
    ]);

    $scriptService = app(AgentUpdateScriptService::class);
    $script = $scriptService->generateOpenClawScript($agent1);

    // Both agents should be in the config
    expect($script)->toContain('agent-one')
        ->toContain('agent-two')
        ->toContain('Alpha')
        ->toContain('Beta');
});

test('buildSignedUrl generates valid HMAC URL', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
    ]);

    $scriptService = app(AgentUpdateScriptService::class);
    $url = $scriptService->buildSignedUrl($agent);

    expect($url)->toContain("/api/agents/{$agent->id}/update-script")
        ->toContain('expires_at=')
        ->toContain('signature=');
});

test('buildCallbackUrl generates valid HMAC URL', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
    ]);

    $scriptService = app(AgentUpdateScriptService::class);
    $url = $scriptService->buildCallbackUrl($agent);

    expect($url)->toContain('/api/webhooks/agent-update')
        ->toContain("agent_id={$agent->id}")
        ->toContain('expires_at=')
        ->toContain('signature=');
});

test('openclaw update script writes BOOTSTRAP.md only if missing', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'harness_agent_id' => 'agent-abc',
        'harness_type' => HarnessType::OpenClaw,
        'name' => 'Atlas',
        'status' => AgentStatus::Active,
    ]);

    $scriptService = app(AgentUpdateScriptService::class);
    $script = $scriptService->generateOpenClawScript($agent);

    // BOOTSTRAP.md should be conditional
    expect($script)->toContain('if [ ! -f')
        ->toContain('BOOTSTRAP.md');
});

test('hermes update script preserves BROWSER_CDP_URL from existing env', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'harness_agent_id' => 'hermes-abc',
        'harness_type' => HarnessType::Hermes,
        'name' => 'Mercury',
        'status' => AgentStatus::Active,
        'model_primary' => 'claude-sonnet-4-20250514',
    ]);

    $scriptService = app(AgentUpdateScriptService::class);
    $script = $scriptService->generateHermesScript($agent);

    // Should capture existing CDP_URL before overwriting .env
    expect($script)->toContain("CDP_URL=\$(grep '^BROWSER_CDP_URL='")
        ->toContain('[ -n "$CDP_URL" ] && echo "$CDP_URL"');
});
