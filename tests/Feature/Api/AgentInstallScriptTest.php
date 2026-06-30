<?php

use App\Enums\AgentStatus;
use App\Models\Agent;
use App\Models\Server;
use App\Models\Team;
use App\Services\AgentInstallScriptService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('install script endpoint returns script with valid signature', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->deploying()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'harness_agent_id' => 'agent-abc',
        'name' => 'Atlas',
        'system_prompt' => 'You are helpful.',
        'identity' => 'Agent identity.',
    ]);

    $expiresAt = now()->addMinutes(10)->timestamp;
    $signature = hash_hmac('sha256', "install|{$agent->id}|{$expiresAt}", config('app.key'));

    $response = $this->get("/api/agents/{$agent->id}/install-script?expires_at={$expiresAt}&signature={$signature}");

    $response->assertOk();
    $response->assertHeader('Content-Type', 'text/plain; charset=UTF-8');

    $script = $response->getContent();
    expect($script)->toContain('#!/bin/bash')
        ->toContain('agent-abc')
        ->toContain('AGENTS.md')
        ->toContain('IDENTITY.md')
        ->toContain('systemctl --user restart openclaw-gateway')
        ->toContain('openclaw health')
        // Core skills are always deployed.
        ->toContain('skills/provision-tasks/SKILL.md')
        ->toContain('skills/provision-publish/provision_publish_tool.js');
});

test('install script includes per-agent browser display services', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->deploying()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'harness_agent_id' => 'agent-abc',
        'name' => 'Atlas',
    ]);

    $expiresAt = now()->addMinutes(10)->timestamp;
    $signature = hash_hmac('sha256', "install|{$agent->id}|{$expiresAt}", config('app.key'));

    $response = $this->get("/api/agents/{$agent->id}/install-script?expires_at={$expiresAt}&signature={$signature}");

    $script = $response->getContent();
    expect($script)
        ->toContain('xvfb-display-')
        ->toContain('chrome-agent-atlas.service')
        ->toContain('x11vnc-agent-atlas.service')
        ->toContain('websockify-agent-atlas.service')
        ->toContain('/etc/caddy/conf.d/agent-atlas.caddy')
        ->toContain('handle_path /browser/agent-atlas/')
        ->toContain('.chrome-profiles/agent-atlas')
        ->toContain('browser.profiles');
});

test('install script includes ONBOARDING.md with onboarding checklist', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->deploying()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'harness_agent_id' => 'agent-abc',
        'name' => 'Atlas',
        'job_description' => 'Pull daily reports from Mixpanel and Google Analytics. Post a morning brief to Slack every day at 9am.',
    ]);

    $expiresAt = now()->addMinutes(10)->timestamp;
    $signature = hash_hmac('sha256', "install|{$agent->id}|{$expiresAt}", config('app.key'));

    $response = $this->get("/api/agents/{$agent->id}/install-script?expires_at={$expiresAt}&signature={$signature}");

    $script = $response->getContent();
    expect($script)
        ->toContain('ONBOARDING.md')
        ->toContain('Welcome to the team, Atlas!')
        ->toContain('Onboarding Checklist')
        ->toContain('Pull daily reports from Mixpanel')
        ->toContain('Review your job description and identify what you need')
        ->toContain('Introduce yourself')
        ->toContain('Set up GitHub')
        // Reserved filename — never re-introduce; OpenClaw auto-removes it.
        ->not->toContain('BOOTSTRAP.md');
});

test('onboarding content omits job-specific step when no job description', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->deploying()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'name' => 'Luna',
    ]);

    $content = AgentInstallScriptService::buildOnboardingContent($agent);
    expect($content)
        ->toContain('Welcome to the team, Luna!')
        ->toContain('Introduce yourself')
        ->toContain('Set up GitHub')
        ->not->toContain('Your Role')
        ->not->toContain('Review your job description');
});

test('onboarding content tells the agent to use web chat when available', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->deploying()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'harness_agent_id' => 'agent-riley',
        'name' => 'Riley',
    ]);

    // Observer auto-creates web connection on agent creation.
    expect($agent->fresh()->webConnection)->not->toBeNull();

    $content = AgentInstallScriptService::buildOnboardingContent($agent->fresh());
    expect($content)
        ->toContain('How to talk to your team')
        ->toContain('Provision web chat')
        ->toContain('ask the user via web chat');
});

test('onboarding content credential pattern guides through API key + OAuth flows', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->deploying()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'harness_agent_id' => 'agent-riley-2',
        'name' => 'Riley',
    ]);
    $agent->tools()->create([
        'name' => 'Ahrefs',
        'url' => 'https://ahrefs.com',
        'category' => 'seo',
    ]);

    $content = AgentInstallScriptService::buildOnboardingContent($agent);
    expect($content)
        ->toContain('Try to sign up yourself')
        ->toContain('OAuth')
        ->toContain('NEVER write secrets to MEMORY.md')
        ->toContain('| Ahrefs | [https://ahrefs.com]');
});

test('install script endpoint rejects invalid signature', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->deploying()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
    ]);

    $expiresAt = now()->addMinutes(10)->timestamp;

    $response = $this->get("/api/agents/{$agent->id}/install-script?expires_at={$expiresAt}&signature=invalid-sig");

    $response->assertForbidden();
});

test('install script endpoint rejects expired signature', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->deploying()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
    ]);

    $expiresAt = now()->subMinute()->timestamp;
    $signature = hash_hmac('sha256', "install|{$agent->id}|{$expiresAt}", config('app.key'));

    $response = $this->get("/api/agents/{$agent->id}/install-script?expires_at={$expiresAt}&signature={$signature}");

    $response->assertForbidden();
});

test('agent ready callback marks agent as active', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->deploying()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
    ]);

    $expiresAt = now()->addMinutes(30)->timestamp;
    $signature = hash_hmac('sha256', "callback|{$agent->id}|{$expiresAt}", config('app.key'));

    $response = $this->postJson("/api/webhooks/agent-ready?agent_id={$agent->id}&expires_at={$expiresAt}&signature={$signature}", [
        'agent_id' => $agent->id,
        'status' => 'ready',
        'expires_at' => $expiresAt,
        'signature' => $signature,
    ]);

    $response->assertOk();
    expect($agent->fresh()->status)->toBe(AgentStatus::Active);
    expect($server->events()->where('event', 'agent_install_complete')->exists())->toBeTrue();
});

test('agent error callback marks agent as error', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->deploying()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
    ]);

    $expiresAt = now()->addMinutes(30)->timestamp;
    $signature = hash_hmac('sha256', "callback|{$agent->id}|{$expiresAt}", config('app.key'));

    $response = $this->postJson("/api/webhooks/agent-ready?agent_id={$agent->id}&expires_at={$expiresAt}&signature={$signature}", [
        'agent_id' => $agent->id,
        'status' => 'error',
        'expires_at' => $expiresAt,
        'signature' => $signature,
    ]);

    $response->assertOk();
    expect($agent->fresh()->status)->toBe(AgentStatus::Error);
    expect($server->events()->where('event', 'agent_install_error')->exists())->toBeTrue();
});

test('agent callback rejects invalid signature', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->deploying()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
    ]);

    $expiresAt = now()->addMinutes(30)->timestamp;

    $response = $this->postJson("/api/webhooks/agent-ready?agent_id={$agent->id}&expires_at={$expiresAt}&signature=bad-sig", [
        'agent_id' => $agent->id,
        'status' => 'ready',
        'expires_at' => $expiresAt,
        'signature' => 'bad-sig',
    ]);

    $response->assertForbidden();
});

test('agent callback rejects expired signature', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->deploying()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
    ]);

    $expiresAt = now()->subMinute()->timestamp;
    $signature = hash_hmac('sha256', "callback|{$agent->id}|{$expiresAt}", config('app.key'));

    $response = $this->postJson("/api/webhooks/agent-ready?agent_id={$agent->id}&expires_at={$expiresAt}&signature={$signature}", [
        'agent_id' => $agent->id,
        'status' => 'ready',
        'expires_at' => $expiresAt,
        'signature' => $signature,
    ]);

    $response->assertForbidden();
});
