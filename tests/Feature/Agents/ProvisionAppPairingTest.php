<?php

use App\Contracts\CommandExecutor;
use App\Enums\AgentStatus;
use App\Enums\CloudProvider;
use App\Enums\HarnessType;
use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Models\Agent;
use App\Models\MobilePairingHandoff;
use App\Models\Server;
use App\Models\User;
use App\Services\HarnessManager;
use App\Support\OpenClawGatewayEndpoint;

beforeEach(function () {
    $this->withoutVite();

    config([
        'openclaw.mobile_pairing.exchange_url' => 'https://app.provision.ai/api/mobile/pairing/exchange',
        'openclaw.mobile_pairing.handoff_ttl_seconds' => 300,
    ]);
});

function pairingAgent(User $user, array $agentAttributes = [], array $serverAttributes = []): Agent
{
    $team = $user->currentTeam;
    $server = Server::factory()->running()->create([
        'team_id' => $team->id,
        'ipv4_address' => '203.0.113.42',
        ...$serverAttributes,
    ]);

    return Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'harness_type' => HarnessType::OpenClaw,
        'status' => AgentStatus::Active,
        'harness_agent_id' => 'agent-scout',
        'name' => 'Scout',
        'emoji' => '🧭',
        ...$agentAttributes,
    ]);
}

function mockPairingExecutor(Server $server, ?string $setupCode = null): CommandExecutor
{
    $executor = Mockery::mock(CommandExecutor::class);
    $executor->shouldReceive('exec')->andReturnUsing(function (string $command) use ($server, $setupCode): string {
        if ($command === "if [ -f '/etc/caddy/Caddyfile' ]; then cat '/etc/caddy/Caddyfile'; fi") {
            return OpenClawGatewayEndpoint::caddyfile($server);
        }

        if ($command === "openclaw qr --json --url '".OpenClawGatewayEndpoint::wssUrl($server)."'" && $setupCode !== null) {
            return json_encode([
                'setupCode' => $setupCode,
                'gatewayUrl' => OpenClawGatewayEndpoint::wssUrl($server),
                'auth' => 'bootstrap',
            ], JSON_THROW_ON_ERROR);
        }

        return '';
    });
    $executor->shouldReceive('writeFile')->andReturnNull();

    $harness = Mockery::mock(HarnessManager::class);
    $harness->shouldReceive('resolveExecutor')->andReturn($executor);
    app()->instance(HarnessManager::class, $harness);

    return $executor;
}

function decodePairingEnvelope(string $pairingCode): array
{
    $base64 = strtr($pairingCode, '-_', '+/');
    $base64 .= str_repeat('=', (4 - strlen($base64) % 4) % 4);

    return json_decode(base64_decode($base64, true), true, flags: JSON_THROW_ON_ERROR);
}

test('team admin can view the Provision App pairing page', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $agent = pairingAgent($user);

    $agent->forceFill([
        'api_server_key' => 'gateway-secret-must-not-leak',
        'default_password' => 'password-must-not-leak',
        'config_snapshot' => [
            'gateway' => ['auth' => ['token' => 'snapshot-secret-must-not-leak']],
        ],
    ])->save();

    $response = $this->actingAs($user)
        ->get(route('agents.provision-app.show', $agent))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('agents/provision-app')
            ->where('agent', [
                'id' => $agent->id,
                'name' => 'Scout',
                'emoji' => '🧭',
            ])
            ->where('canPair', true)
            ->where('unavailableReason', null));

    expect($response->getContent())
        ->not->toContain('gateway-secret-must-not-leak')
        ->not->toContain('password-must-not-leak')
        ->not->toContain('snapshot-secret-must-not-leak');
});

test('another team cannot view a Provision App pairing page', function () {
    $owner = User::factory()->withPersonalTeam()->create();
    $agent = pairingAgent($owner);
    $otherUser = User::factory()->withPersonalTeam()->create();

    $this->actingAs($otherUser)
        ->get(route('agents.provision-app.show', $agent))
        ->assertNotFound();
});

test('non admin cannot view a Provision App pairing page', function () {
    $owner = User::factory()->withPersonalTeam()->create();
    $agent = pairingAgent($owner);
    $team = $owner->currentTeam;
    $member = User::factory()->withPersonalTeam()->create();
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $member->forceFill(['current_team_id' => $team->id])->save();

    $this->actingAs($member->fresh())
        ->get(route('agents.provision-app.show', $agent))
        ->assertForbidden();
});

test('a handoff contains a QR and copyable Provision envelope but stores only a token hash', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $agent = pairingAgent($user);
    mockPairingExecutor($agent->server);

    $response = $this->actingAs($user)
        ->postJson(route('agents.provision-app.handoffs.store', $agent))
        ->assertCreated()
        ->assertJsonStructure([
            'handoffId',
            'qrSvg',
            'pairingCode',
            'expiresAt',
            'statusUrl',
        ]);

    expect($response->json('qrSvg'))->toContain('<svg');
    expect($response->json('statusUrl'))->toStartWith('/agents/');

    $envelope = decodePairingEnvelope($response->json('pairingCode'));
    expect($envelope['v'])->toBe(1)
        ->and($envelope['type'])->toBe('provision-agent')
        ->and($envelope['agentId'])->toBe('agent-scout')
        ->and($envelope['agentName'])->toBe('Scout')
        ->and($envelope['agentEmoji'])->toBe('🧭')
        ->and($envelope['exchange']['url'])->toBe('https://app.provision.ai/api/mobile/pairing/exchange')
        ->and($envelope)->not->toHaveKey('setupCode');

    $rawToken = $envelope['exchange']['token'];
    $handoff = MobilePairingHandoff::query()->sole();

    expect($rawToken)->toHaveLength(64)
        ->and($handoff->token_hash)->toBe(hash('sha256', $rawToken))
        ->and($handoff->token_hash)->not->toBe($rawToken)
        ->and(json_encode($handoff->getAttributes()))->not->toContain($rawToken);
});

test('creating a new code revokes an earlier unused code', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $agent = pairingAgent($user);
    mockPairingExecutor($agent->server);

    $this->actingAs($user)->postJson(route('agents.provision-app.handoffs.store', $agent))->assertCreated();
    $first = MobilePairingHandoff::query()->sole();

    $this->actingAs($user)->postJson(route('agents.provision-app.handoffs.store', $agent))->assertCreated();

    expect($first->fresh()->revoked_at)->not->toBeNull()
        ->and(MobilePairingHandoff::query()->count())->toBe(2);
});

test('only active OpenClaw agents on running servers can create a handoff', function (array $agentAttributes, array $serverAttributes, int $expectedStatus) {
    $user = User::factory()->withPersonalTeam()->create();
    $agent = pairingAgent($user, $agentAttributes, $serverAttributes);

    $this->actingAs($user)
        ->postJson(route('agents.provision-app.handoffs.store', $agent))
        ->assertStatus($expectedStatus);

    expect(MobilePairingHandoff::query()->count())->toBe(0);
})->with([
    'Hermes' => [['harness_type' => HarnessType::Hermes], [], 422],
    'pending agent' => [['status' => AgentStatus::Pending], [], 422],
    'stopped server' => [[], ['status' => ServerStatus::Stopped], 302],
    'local Docker server' => [[], ['cloud_provider' => CloudProvider::Docker], 422],
]);

test('a handoff cannot cross the agent team boundary through a mismatched server', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $otherUser = User::factory()->withPersonalTeam()->create();
    $agent = pairingAgent($user);
    $otherServer = Server::factory()->running()->create([
        'team_id' => $otherUser->current_team_id,
        'ipv4_address' => '203.0.113.84',
    ]);
    $agent->forceFill(['server_id' => $otherServer->id])->save();

    $this->actingAs($user)
        ->postJson(route('agents.provision-app.handoffs.store', $agent))
        ->assertServiceUnavailable();

    expect(MobilePairingHandoff::query()->count())->toBe(0);
});

test('a valid handoff is atomically exchanged for an OpenClaw setup code once', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $agent = pairingAgent($user);
    $rawToken = str_repeat('a', 64);
    $setupPayload = json_encode([
        'url' => OpenClawGatewayEndpoint::wssUrl($agent->server),
        'bootstrapToken' => str_repeat('bootstrap-token-', 4),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $setupCode = rtrim(strtr(base64_encode($setupPayload), '+/', '-_'), '=');
    $handoff = MobilePairingHandoff::query()->create([
        'team_id' => $agent->team_id,
        'agent_id' => $agent->id,
        'server_id' => $agent->server_id,
        'created_by_user_id' => $user->id,
        'token_hash' => hash('sha256', $rawToken),
        'expires_at' => now()->addMinutes(5),
    ]);

    $executor = Mockery::mock(CommandExecutor::class);
    $executor->shouldReceive('exec')
        ->once()
        ->with("openclaw qr --json --url '".OpenClawGatewayEndpoint::wssUrl($agent->server)."'")
        ->andReturn(json_encode(['setupCode' => $setupCode], JSON_THROW_ON_ERROR));
    $harness = Mockery::mock(HarnessManager::class);
    $harness->shouldReceive('resolveExecutor')->once()->andReturn($executor);
    app()->instance(HarnessManager::class, $harness);

    $this->postJson(route('api.mobile.pairing.exchange'), ['token' => $rawToken])
        ->assertSuccessful()
        ->assertExactJson(['setupCode' => $setupCode]);

    $this->postJson(route('api.mobile.pairing.exchange'), ['token' => $rawToken])
        ->assertGone()
        ->assertExactJson(['message' => 'This pairing handoff is no longer available.']);

    expect($handoff->fresh()->consumed_at)->not->toBeNull()
        ->and($handoff->fresh()->completed_at)->not->toBeNull()
        ->and($handoff->fresh()->failed_at)->toBeNull();
});

test('invalid expired and revoked handoffs return the same gone response', function (string $rawToken, Closure $handoffState) {
    $user = User::factory()->withPersonalTeam()->create();
    $agent = pairingAgent($user);
    $attributes = $handoffState($agent, $user, $rawToken);

    if ($attributes !== null) {
        MobilePairingHandoff::query()->create($attributes);
    }

    $this->postJson(route('api.mobile.pairing.exchange'), ['token' => $rawToken])
        ->assertGone()
        ->assertExactJson(['message' => 'This pairing handoff is no longer available.']);
})->with([
    'invalid' => [str_repeat('x', 64), fn () => null],
    'expired' => [str_repeat('y', 64), fn (Agent $agent, User $user, string $token) => [
        'team_id' => $agent->team_id,
        'agent_id' => $agent->id,
        'server_id' => $agent->server_id,
        'created_by_user_id' => $user->id,
        'token_hash' => hash('sha256', $token),
        'expires_at' => now()->subSecond(),
    ]],
    'revoked' => [str_repeat('z', 64), fn (Agent $agent, User $user, string $token) => [
        'team_id' => $agent->team_id,
        'agent_id' => $agent->id,
        'server_id' => $agent->server_id,
        'created_by_user_id' => $user->id,
        'token_hash' => hash('sha256', $token),
        'expires_at' => now()->addMinutes(5),
        'revoked_at' => now(),
    ]],
]);

test('malformed gateway output consumes the handoff and returns no secret details', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $agent = pairingAgent($user);
    $rawToken = str_repeat('b', 64);
    $handoff = MobilePairingHandoff::query()->create([
        'team_id' => $agent->team_id,
        'agent_id' => $agent->id,
        'server_id' => $agent->server_id,
        'created_by_user_id' => $user->id,
        'token_hash' => hash('sha256', $rawToken),
        'expires_at' => now()->addMinutes(5),
    ]);

    $executor = Mockery::mock(CommandExecutor::class);
    $executor->shouldReceive('exec')->once()->andReturn('{"gatewayToken":"must-not-leak"}');
    $harness = Mockery::mock(HarnessManager::class);
    $harness->shouldReceive('resolveExecutor')->once()->andReturn($executor);
    app()->instance(HarnessManager::class, $harness);

    $response = $this->postJson(route('api.mobile.pairing.exchange'), ['token' => $rawToken])
        ->assertServiceUnavailable();

    expect($response->getContent())->not->toContain('must-not-leak')
        ->and($handoff->fresh()->consumed_at)->not->toBeNull()
        ->and($handoff->fresh()->failed_at)->not->toBeNull()
        ->and($handoff->fresh()->failure_code)->toBe('gateway_qr_failed');
});

test('exchange refuses a handoff whose agent and server no longer share a team', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $otherUser = User::factory()->withPersonalTeam()->create();
    $agent = pairingAgent($user);
    $otherServer = Server::factory()->running()->create([
        'team_id' => $otherUser->current_team_id,
        'ipv4_address' => '203.0.113.85',
    ]);
    $rawToken = str_repeat('c', 64);
    $handoff = MobilePairingHandoff::query()->create([
        'team_id' => $agent->team_id,
        'agent_id' => $agent->id,
        'server_id' => $otherServer->id,
        'created_by_user_id' => $user->id,
        'token_hash' => hash('sha256', $rawToken),
        'expires_at' => now()->addMinutes(5),
    ]);

    $harness = Mockery::mock(HarnessManager::class);
    $harness->shouldNotReceive('resolveExecutor');
    app()->instance(HarnessManager::class, $harness);

    $this->postJson(route('api.mobile.pairing.exchange'), ['token' => $rawToken])
        ->assertServiceUnavailable();

    expect($handoff->fresh()->consumed_at)->not->toBeNull()
        ->and($handoff->fresh()->failed_at)->not->toBeNull();
});

test('exchange refuses an existing handoff for a local Docker server', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $agent = pairingAgent($user, [], ['cloud_provider' => CloudProvider::Docker]);
    $rawToken = str_repeat('d', 64);
    $handoff = MobilePairingHandoff::query()->create([
        'team_id' => $agent->team_id,
        'agent_id' => $agent->id,
        'server_id' => $agent->server_id,
        'created_by_user_id' => $user->id,
        'token_hash' => hash('sha256', $rawToken),
        'expires_at' => now()->addMinutes(5),
    ]);

    $harness = Mockery::mock(HarnessManager::class);
    $harness->shouldNotReceive('resolveExecutor');
    app()->instance(HarnessManager::class, $harness);

    $this->postJson(route('api.mobile.pairing.exchange'), ['token' => $rawToken])
        ->assertServiceUnavailable();

    expect($handoff->fresh()->consumed_at)->not->toBeNull()
        ->and($handoff->fresh()->failed_at)->not->toBeNull();
});

test('handoff status is scoped to its team and agent', function () {
    $owner = User::factory()->withPersonalTeam()->create();
    $agent = pairingAgent($owner);
    $handoff = MobilePairingHandoff::query()->create([
        'team_id' => $agent->team_id,
        'agent_id' => $agent->id,
        'server_id' => $agent->server_id,
        'created_by_user_id' => $owner->id,
        'token_hash' => hash('sha256', 'status-token'),
        'expires_at' => now()->addMinutes(5),
    ]);
    $otherUser = User::factory()->withPersonalTeam()->create();

    $this->actingAs($owner)
        ->getJson(route('agents.provision-app.handoffs.show', [$agent, $handoff]))
        ->assertSuccessful()
        ->assertJsonPath('status', 'ready');

    $this->actingAs($otherUser)
        ->getJson(route('agents.provision-app.handoffs.show', [$agent, $handoff]))
        ->assertNotFound();
});

test('a claimed handoff remains processing after its claim window expires', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $agent = pairingAgent($user);
    $handoff = MobilePairingHandoff::query()->create([
        'team_id' => $agent->team_id,
        'agent_id' => $agent->id,
        'server_id' => $agent->server_id,
        'created_by_user_id' => $user->id,
        'token_hash' => hash('sha256', 'processing-token'),
        'expires_at' => now()->subSecond(),
        'consumed_at' => now()->subSeconds(2),
    ]);

    $this->actingAs($user)
        ->getJson(route('agents.provision-app.handoffs.show', [$agent, $handoff]))
        ->assertSuccessful()
        ->assertJsonPath('status', 'processing');
});
