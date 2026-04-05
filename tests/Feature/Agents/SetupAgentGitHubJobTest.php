<?php

use App\Enums\AgentStatus;
use App\Jobs\SetupAgentGitHubJob;
use App\Models\Agent;
use App\Models\AgentEmailConnection;
use App\Models\Server;
use App\Models\Team;
use App\Services\SshService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('it skips github setup if agent has no email connection', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'status' => AgentStatus::Active,
    ]);

    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldNotReceive('connect');

    (new SetupAgentGitHubJob($agent))->handle($sshService);
});

test('it skips github setup if hosts.yml already exists', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'status' => AgentStatus::Active,
        'harness_agent_id' => 'agent-gh-exists',
    ]);
    AgentEmailConnection::factory()->create(['agent_id' => $agent->id]);

    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')->once()->andReturnSelf();
    $sshService->shouldReceive('readFile')
        ->with('/root/.openclaw/agents/agent-gh-exists/.gh/hosts.yml')
        ->once()
        ->andReturn("github.com:\n  oauth_token: gho_abc123\n");
    $sshService->shouldNotReceive('exec');
    $sshService->shouldNotReceive('writeFile');
    $sshService->shouldReceive('disconnect')->once();

    (new SetupAgentGitHubJob($agent))->handle($sshService);
});

test('it creates gh directory and gitconfig for agent', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'status' => AgentStatus::Active,
        'harness_agent_id' => 'agent-gh-setup',
        'name' => 'TestBot',
    ]);
    $emailConnection = AgentEmailConnection::factory()->create([
        'agent_id' => $agent->id,
        'email_address' => 'testbot@provisionagents.com',
    ]);

    $executedCommand = null;
    $writtenGitconfig = null;

    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')->once()->andReturnSelf();
    $sshService->shouldReceive('readFile')
        ->with('/root/.openclaw/agents/agent-gh-setup/.gh/hosts.yml')
        ->once()
        ->andThrow(new RuntimeException('File not found'));
    $sshService->shouldReceive('exec')
        ->with('mkdir -p /root/.openclaw/agents/agent-gh-setup/.gh')
        ->once();
    $sshService->shouldReceive('writeFile')
        ->with('/root/.openclaw/agents/agent-gh-setup/.gitconfig', Mockery::on(function ($content) use (&$writtenGitconfig) {
            $writtenGitconfig = $content;

            return true;
        }))
        ->once();
    $sshService->shouldReceive('disconnect')->once();

    (new SetupAgentGitHubJob($agent))->handle($sshService);

    expect($writtenGitconfig)
        ->toContain('name = TestBot')
        ->toContain('email = testbot@provisionagents.com');
});
