<?php

use App\Enums\HarnessType;
use App\Jobs\EnsureProvisionDaemonCurrentJob;
use App\Models\Agent;
use App\Models\ChatConversation;
use App\Models\OpenClawSessionDiscovery;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{User, Server, Agent}
 */
function openClawDiscoveryExpiryFixture(): array
{
    $admin = User::factory()->withPersonalTeam()->create();
    $server = Server::factory()->running()->create(['team_id' => $admin->currentTeam->id]);
    $agent = Agent::factory()->create([
        'team_id' => $admin->currentTeam->id,
        'server_id' => $server->id,
        'harness_type' => HarnessType::OpenClaw,
        'harness_agent_id' => 'expiry-agent',
    ]);

    return [$admin, $server, $agent];
}

function createDiscoveryAt(Server $server, Agent $agent, string $sessionKey, DateTimeInterface $discoveredAt): OpenClawSessionDiscovery
{
    return OpenClawSessionDiscovery::query()->create([
        'server_id' => $server->id,
        'agent_id' => $agent->id,
        'session_key' => $sessionKey,
        'kind' => 'direct',
        'channel' => 'webchat',
        'title' => $sessionKey,
        'has_active_run' => false,
        'active_run_ids' => [],
        'upstream_updated_at' => $discoveredAt,
        'discovered_at' => $discoveredAt,
    ]);
}

test('chat lists only unclaimed server sessions discovered in the last fifteen minutes', function () {
    Bus::fake([EnsureProvisionDaemonCurrentJob::class]);
    [$admin, $server, $agent] = openClawDiscoveryExpiryFixture();
    $fresh = createDiscoveryAt($server, $agent, 'agent:expiry-agent:webchat:fresh', now()->subMinutes(14));
    createDiscoveryAt($server, $agent, 'agent:expiry-agent:webchat:stale', now()->subMinutes(16));

    $this->actingAs($admin)
        ->get(route('agents.chat', $agent))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->has('serverSessions', 1)
            ->where('serverSessions.0.id', $fresh->id));
});

test('an unclaimed expired server session cannot be imported', function () {
    [$admin, $server, $agent] = openClawDiscoveryExpiryFixture();
    $stale = createDiscoveryAt(
        $server,
        $agent,
        'agent:expiry-agent:webchat:expired-import',
        now()->subMinutes(16),
    );

    $this->actingAs($admin)
        ->postJson(route('agents.chat.server-sessions.import', [$agent, $stale]))
        ->assertNotFound();

    expect(ChatConversation::query()->where('session_key', $stale->session_key)->exists())->toBeFalse()
        ->and($stale->fresh()->claimed_by_user_id)->toBeNull()
        ->and($stale->fresh()->chat_conversation_id)->toBeNull();
});
