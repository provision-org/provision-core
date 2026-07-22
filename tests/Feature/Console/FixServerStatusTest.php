<?php

use App\Enums\ServerStatus;
use App\Models\Agent;
use App\Models\AgentArtifact;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('server reprovision refuses to discard a server that owns artifact state', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'status' => ServerStatus::Error,
    ]);
    $agent = Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'artifact_cleanup_required' => true,
    ]);
    $artifact = AgentArtifact::factory()->live()->create([
        'team_id' => $team->id,
        'agent_id' => $agent->id,
    ]);

    $this->artisan('app:fix-server-status', ['--reprovision' => true])
        ->expectsOutputToContain('while its agents have artifact state')
        ->assertExitCode(Command::FAILURE);

    expect(Server::find($server->id))->not->toBeNull()
        ->and(Agent::find($agent->id)?->server_id)->toBe($server->id)
        ->and(AgentArtifact::find($artifact->id))->not->toBeNull()
        ->and(Server::query()->where('team_id', $team->id)->count())->toBe(1);
});
