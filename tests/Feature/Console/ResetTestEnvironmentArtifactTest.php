<?php

use App\Enums\AgentStatus;
use App\Enums\ServerStatus;
use App\Models\Agent;
use App\Models\AgentApiToken;
use App\Models\Server;
use App\Models\Team;
use App\Services\DigitalOceanService;
use App\Services\HetznerService;
use App\Services\PublishArtifactService;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('test environment reset aborts before cloud teardown when artifact DNS cleanup fails', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'artifact_cleanup_required' => true,
    ]);
    $apiToken = AgentApiToken::createForAgent($agent)['token'];

    $publisher = Mockery::mock(PublishArtifactService::class);
    $publisher->shouldReceive('teardownAgent')
        ->once()
        ->withArgs(fn (Agent $candidate, bool $requireServerCleanup): bool => $candidate->is($agent) && $requireServerCleanup)
        ->andThrow(new RuntimeException('DNS cleanup unavailable'));
    app()->instance(PublishArtifactService::class, $publisher);

    $hetzner = Mockery::mock(HetznerService::class);
    $hetzner->shouldNotReceive('deleteServer');
    app()->instance(HetznerService::class, $hetzner);

    $digitalOcean = Mockery::mock(DigitalOceanService::class);
    $digitalOcean->shouldNotReceive('deleteDroplet');
    app()->instance(DigitalOceanService::class, $digitalOcean);

    $this->artisan('app:reset-test-env', ['--force' => true])
        ->expectsOutputToContain('reset aborted before cloud teardown')
        ->assertExitCode(Command::FAILURE);

    expect(Team::find($team->id))->not->toBeNull()
        ->and(Server::find($server->id)?->status)->toBe(ServerStatus::Destroying)
        ->and(Agent::find($agent->id)?->status)->toBe(AgentStatus::Paused)
        ->and(AgentApiToken::find($apiToken->id))->toBeNull();
});
