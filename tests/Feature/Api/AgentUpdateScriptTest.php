<?php

use App\Enums\AgentStatus;
use App\Enums\HarnessType;
use App\Enums\LlmProvider;
use App\Models\Agent;
use App\Models\Server;
use App\Models\Team;
use App\Services\Scripts\AgentUpdateScriptService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

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
        ->toContain('openclaw gateway call health --timeout 5000');
});

test('openclaw update script syncs binary to pinned version before restart (issue #41)', function () {
    config(['provision.openclaw_version' => '2026.5.99']);

    $team = Team::factory()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'harness_agent_id' => 'agent-pin',
        'harness_type' => HarnessType::OpenClaw,
        'name' => 'Pinned',
        'status' => AgentStatus::Active,
    ]);

    $script = app(AgentUpdateScriptService::class)->generateOpenClawScript($agent);

    // Binary-version guard runs before restart and uses the pinned version.
    expect($script)->toContain("PINNED_OPENCLAW_VERSION='2026.5.99'")
        ->toContain('npm install -g "openclaw@$PINNED_OPENCLAW_VERSION"');

    $pinnedPos = strpos($script, 'PINNED_OPENCLAW_VERSION=');
    $restartPos = strpos($script, 'systemctl --user restart openclaw-gateway');
    expect($pinnedPos)->toBeLessThan($restartPos);
});

test('openclaw update script reports error (not warning) when gateway never becomes healthy (issue #41)', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'harness_agent_id' => 'agent-err',
        'harness_type' => HarnessType::OpenClaw,
        'name' => 'Errors',
        'status' => AgentStatus::Active,
    ]);

    $script = app(AgentUpdateScriptService::class)->generateOpenClawScript($agent);

    expect($script)->toContain('status=error&error_message=openclaw-gateway+failed+to+become+healthy+after+restart')
        ->not->toContain('warning=health_check_failed');
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

test('openclaw update script writes ONBOARDING.md only if missing', function () {
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

    expect($script)
        ->toContain('if [ ! -f')
        ->toContain('ONBOARDING.md')
        // Cleanup of the legacy reserved filename runs unconditionally.
        ->toContain('rm -f')
        ->toContain('/BOOTSTRAP.md');
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

test('openclaw update config preserves browser.profiles for every agent on the server (issue #27)', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);

    Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'harness_agent_id' => 'agent-scout-id',
        'harness_type' => HarnessType::OpenClaw,
        'name' => 'scout',
        'browser_display_num' => 1,
        'status' => AgentStatus::Active,
    ]);
    Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'harness_agent_id' => 'agent-buddy-id',
        'harness_type' => HarnessType::OpenClaw,
        'name' => 'buddy',
        'browser_display_num' => 2,
        'status' => AgentStatus::Active,
    ]);
    $agent = Agent::where('name', 'scout')->first();

    $config = app(AgentUpdateScriptService::class)->buildOpenClawConfigSnapshot($agent);

    expect($config['browser']['profiles'])->toBeArray()->toHaveCount(2)
        ->toHaveKey('agent-scout')
        ->toHaveKey('agent-buddy');

    expect($config['browser']['profiles']['agent-scout'])
        ->toMatchArray([
            'driver' => 'existing-session',
            'attachOnly' => true,
            'cdpUrl' => 'http://127.0.0.1:9223',
        ]);

    expect($config['browser']['profiles']['agent-buddy']['cdpUrl'])
        ->toBe('http://127.0.0.1:9224');
});

test('openclaw update config omits browser.profiles when no openclaw agents have a display num', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);

    $agent = Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'harness_agent_id' => 'agent-legacy',
        'harness_type' => HarnessType::OpenClaw,
        'name' => 'legacy',
        'browser_display_num' => null,
        'status' => AgentStatus::Active,
    ]);

    $config = app(AgentUpdateScriptService::class)->buildOpenClawConfigSnapshot($agent);

    expect($config['browser'])->not->toHaveKey('profiles');
});

test('chatgpt-subscription agents heartbeat on their own model, not openrouter', function () {
    $chatgpt = Agent::factory()->make([
        'auth_provider' => 'chatgpt',
        'model_primary' => 'gpt-5.5',
        'model_fallbacks' => [],
    ]);
    expect($chatgpt->openclawHeartbeatConfig())
        ->toBe(['model' => 'openai-codex/gpt-5.5', 'lightContext' => true]);

    $managed = Agent::factory()->make([
        'auth_provider' => 'openrouter',
        'model_primary' => 'z-ai/glm-4.7',
    ]);
    expect($managed->openclawHeartbeatConfig())->toBeNull();
});

test('openclaw config gives chatgpt agents a per-agent heartbeat override', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'harness_agent_id' => 'agent-gpt',
        'harness_type' => HarnessType::OpenClaw,
        'status' => AgentStatus::Active,
        'auth_provider' => 'chatgpt',
        'model_primary' => 'gpt-5.5',
        'model_fallbacks' => [],
    ]);

    $config = app(AgentUpdateScriptService::class)->buildOpenClawConfigSnapshot($agent);
    $entry = collect($config['agents']['list'])->firstWhere('id', 'agent-gpt');

    // Per-agent heartbeat uses the ChatGPT model (billed via OpenAI)…
    expect($entry['heartbeat'])->toBe(['model' => 'openai-codex/gpt-5.5', 'lightContext' => true]);
    // …while the server-wide default still uses the managed automation model.
    expect($config['agents']['defaults']['heartbeat']['model'])
        ->toBe(LlmProvider::AUTOMATION_MODEL);
});

test('openclaw config enables the provision-publish skill', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'harness_agent_id' => 'agent-pub',
        'harness_type' => HarnessType::OpenClaw,
        'status' => AgentStatus::Active,
    ]);

    $config = app(AgentUpdateScriptService::class)->buildOpenClawConfigSnapshot($agent);

    // Both core skills are enabled so OpenClaw exposes their tools to the agent.
    expect($config['skills']['entries']['provision-tasks'])->toBe(['enabled' => true])
        ->and($config['skills']['entries']['provision-publish'])->toBe(['enabled' => true]);
});

test('the update script deploys the provision-publish skill files', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'harness_agent_id' => 'agent-pub2',
        'harness_type' => HarnessType::OpenClaw,
        'status' => AgentStatus::Active,
    ]);

    $script = app(AgentUpdateScriptService::class)->generateOpenClawScript($agent);

    expect($script)->toContain('skills/provision-publish/SKILL.md')
        ->toContain('skills/provision-publish/provision_publish_tool.js')
        ->toContain('skills/provision-publish/skill.json');
});

test('managed agents keep the default heartbeat (no per-agent override)', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'harness_agent_id' => 'agent-mgd',
        'harness_type' => HarnessType::OpenClaw,
        'status' => AgentStatus::Active,
        'auth_provider' => 'openrouter',
        'model_primary' => 'z-ai/glm-4.7',
    ]);

    $config = app(AgentUpdateScriptService::class)->buildOpenClawConfigSnapshot($agent);
    $entry = collect($config['agents']['list'])->firstWhere('id', 'agent-mgd');

    expect($entry)->not->toHaveKey('heartbeat');
});
