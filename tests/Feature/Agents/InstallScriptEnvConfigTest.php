<?php

use App\Enums\LlmProvider;
use App\Models\Agent;
use App\Models\Server;
use App\Models\Team;
use App\Models\TeamApiKey;
use App\Services\AgentInstallScriptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Provision\Billing\Models\ManagedOpenRouterKey;

uses(RefreshDatabase::class);

test('install script patches openclaw.json env section with BYOK api keys', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->deploying()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'harness_agent_id' => 'agent-env-test',
    ]);

    TeamApiKey::factory()->create([
        'team_id' => $team->id,
        'provider' => LlmProvider::OpenRouter,
        'api_key' => 'sk-or-test-key',
        'is_active' => true,
    ]);

    TeamApiKey::factory()->create([
        'team_id' => $team->id,
        'provider' => LlmProvider::Anthropic,
        'api_key' => 'sk-ant-test-key',
        'is_active' => true,
    ]);

    $script = app(AgentInstallScriptService::class)->generateScript($agent);

    // Env section patch should set keys in openclaw.json
    expect($script)
        ->toContain('c.env["OPENROUTER_API_KEY"] = "sk-or-test-key"')
        ->toContain('c.env["ANTHROPIC_API_KEY"] = "sk-ant-test-key"')
        // OpenRouter aliased as OpenAI since no native OpenAI key
        ->toContain('c.env["OPENAI_API_KEY"] = "sk-or-test-key"');

    // .env file should still be written for skills
    expect($script)
        ->toContain('OPENROUTER_API_KEY=sk-or-test-key')
        ->toContain('ANTHROPIC_API_KEY=sk-ant-test-key');
});

test('install script patches openclaw.json env section with managed key when no BYOK', function () {
    if (! class_exists('Provision\Billing\Models\ManagedOpenRouterKey')) {
        $this->markTestSkipped('Requires billing module');
    }

    $team = Team::factory()->create();
    ManagedOpenRouterKey::factory()->create([
        'team_id' => $team->id,
        'api_key' => 'sk-or-managed-key',
    ]);

    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->deploying()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'harness_agent_id' => 'agent-managed-test',
    ]);

    $script = app(AgentInstallScriptService::class)->generateScript($agent);

    expect($script)
        ->toContain('c.env["OPENROUTER_API_KEY"] = "sk-or-managed-key"')
        ->toContain('c.env["OPENAI_API_KEY"] = "sk-or-managed-key"');
});

test('install script skips env config patch when no api keys exist', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->deploying()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'harness_agent_id' => 'agent-no-keys',
    ]);

    $script = app(AgentInstallScriptService::class)->generateScript($agent);

    expect($script)
        ->not->toContain('c.env[')
        ->not->toContain('LLM provider API keys');
});

test('install script does not alias OpenAI key when team has native OpenAI key', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->deploying()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'harness_agent_id' => 'agent-openai-test',
    ]);

    TeamApiKey::factory()->create([
        'team_id' => $team->id,
        'provider' => LlmProvider::OpenRouter,
        'api_key' => 'sk-or-key',
        'is_active' => true,
    ]);

    TeamApiKey::factory()->create([
        'team_id' => $team->id,
        'provider' => LlmProvider::OpenAi,
        'api_key' => 'sk-openai-native',
        'is_active' => true,
    ]);

    $script = app(AgentInstallScriptService::class)->generateScript($agent);

    // Should use native OpenAI key, not alias from OpenRouter
    expect($script)
        ->toContain('c.env["OPENAI_API_KEY"] = "sk-openai-native"')
        ->toContain('c.env["OPENROUTER_API_KEY"] = "sk-or-key"');
});
