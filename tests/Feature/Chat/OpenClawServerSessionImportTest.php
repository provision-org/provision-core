<?php

use App\Contracts\CommandExecutor;
use App\Enums\ChatMessageRole;
use App\Enums\HarnessType;
use App\Enums\TeamRole;
use App\Events\ChatMessageReceivedEvent;
use App\Jobs\ReconcileOpenClawChatMessageJob;
use App\Jobs\SyncOpenClawConversationJob;
use App\Models\Agent;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\OpenClawSessionDiscovery;
use App\Models\Server;
use App\Models\User;
use App\Services\HarnessManager;
use App\Services\OpenClawChatService;
use App\Services\OpenClawSessionSyncService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

/**
 * @return array{User, Server, Agent}
 */
function openClawImportFixture(string $token = 'session-daemon-token'): array
{
    $admin = User::factory()->withPersonalTeam()->create();
    $server = Server::factory()->running()->create([
        'team_id' => $admin->currentTeam->id,
        'daemon_token' => $token,
    ]);
    $agent = Agent::factory()->create([
        'team_id' => $admin->currentTeam->id,
        'server_id' => $server->id,
        'harness_type' => HarnessType::OpenClaw,
        'harness_agent_id' => 'session-agent',
    ]);

    return [$admin, $server, $agent];
}

function createOpenClawDiscovery(Server $server, Agent $agent, string $key = 'agent:session-agent:slack:thread:123'): OpenClawSessionDiscovery
{
    return OpenClawSessionDiscovery::query()->create([
        'server_id' => $server->id,
        'agent_id' => $agent->id,
        'session_key' => $key,
        'kind' => 'direct',
        'channel' => 'slack',
        'chat_type' => 'direct',
        'title' => 'Imported support thread',
        'preview' => 'A bounded preview',
        'has_active_run' => false,
        'active_run_ids' => [],
        'upstream_updated_at' => now()->subMinute(),
        'discovered_at' => now(),
    ]);
}

test('daemon snapshots reconcile known sessions but discover only eligible sessions on their server', function () {
    [$admin, $server, $agent] = openClawImportFixture();
    $known = ChatConversation::factory()->create([
        'agent_id' => $agent->id,
        'user_id' => $admin->id,
    ]);
    $known->forceFill([
        'session_key' => "agent:session-agent:dashboard:{$known->id}",
    ])->save();
    $active = ChatMessage::factory()->create([
        'chat_conversation_id' => $known->id,
        'role' => ChatMessageRole::User,
        'delivery_status' => 'running',
        'upstream_run_id' => 'known-active-run',
    ]);

    $foreignAdmin = User::factory()->withPersonalTeam()->create();
    $foreignServer = Server::factory()->running()->create(['team_id' => $foreignAdmin->currentTeam->id]);
    $foreignAgent = Agent::factory()->create([
        'team_id' => $foreignAdmin->currentTeam->id,
        'server_id' => $foreignServer->id,
        'harness_agent_id' => 'foreign-session-agent',
    ]);
    $hermes = Agent::factory()->create([
        'team_id' => $admin->currentTeam->id,
        'server_id' => $server->id,
        'harness_type' => HarnessType::Hermes,
        'harness_agent_id' => 'hermes-session-agent',
    ]);
    Bus::fake([ReconcileOpenClawChatMessageJob::class]);

    $this->withToken('session-daemon-token')->postJson("/api/daemon/servers/{$server->id}/chat/sessions/snapshot", [
        'sessions' => [
            [
                'agentId' => $agent->harness_agent_id,
                'key' => $known->session_key,
                'kind' => 'unknown',
                'hasActiveRun' => true,
                'activeRunIds' => ['known-active-run'],
            ],
            [
                'agentId' => $agent->harness_agent_id,
                'key' => 'agent:session-agent:slack:thread:123',
                'kind' => 'direct',
                'channel' => 'slack',
                'displayName' => 'Support thread',
                'lastMessagePreview' => 'Customer needs help',
            ],
            [
                'agentId' => $agent->harness_agent_id,
                'key' => 'agent:session-agent:cron:daily',
                'kind' => 'direct',
            ],
            [
                'agentId' => $foreignAgent->harness_agent_id,
                'key' => 'agent:foreign-session-agent:slack:thread:456',
                'kind' => 'direct',
            ],
            [
                'agentId' => $hermes->harness_agent_id,
                'key' => 'agent:hermes-session-agent:slack:thread:789',
                'kind' => 'direct',
            ],
        ],
    ])->assertSuccessful()->assertJsonPath('ingested', 1);

    $this->assertDatabaseHas('openclaw_session_discoveries', [
        'server_id' => $server->id,
        'agent_id' => $agent->id,
        'session_key' => 'agent:session-agent:slack:thread:123',
    ]);
    expect(OpenClawSessionDiscovery::query()->count())->toBe(1);
    Bus::assertDispatched(
        ReconcileOpenClawChatMessageJob::class,
        fn (ReconcileOpenClawChatMessageJob $job) => $job->conversation->is($known)
            && $job->userMessage->is($active),
    );
});

test('only team admins can import and an import remains private and idempotent', function () {
    [$admin, $server, $agent] = openClawImportFixture();
    $discovery = createOpenClawDiscovery($server, $agent);
    $member = User::factory()->create();
    $admin->currentTeam->members()->attach($member, ['role' => TeamRole::Member->value]);
    $member->switchTeam($admin->currentTeam);

    $this->actingAs($member)
        ->postJson(route('agents.chat.server-sessions.import', [$agent, $discovery]))
        ->assertForbidden();

    $executor = Mockery::mock(CommandExecutor::class);
    $executor->shouldReceive('exec')
        ->once()
        ->with(Mockery::on(fn (string $command) => str_contains($command, "'chat.history'")))
        ->andReturn(json_encode([
            'messages' => [
                ['role' => 'system', 'content' => 'private system prompt'],
                [
                    'role' => 'user',
                    'content' => 'Can you help?',
                    '__openclaw' => ['id' => 'import-user-1', 'seq' => 10],
                    'timestamp' => now()->subMinutes(2)->getTimestampMs(),
                ],
                ['role' => 'toolResult', 'content' => 'secret tool output'],
                [
                    'role' => 'assistant',
                    'content' => 'Yes, I can help.',
                    '__openclaw' => ['id' => 'import-assistant-1', 'seq' => 11],
                    'timestamp' => now()->subMinute()->getTimestampMs(),
                ],
            ],
        ]));
    $manager = Mockery::mock(HarnessManager::class);
    $manager->shouldReceive('resolveExecutor')
        ->once()
        ->withArgs(fn (Server $value) => $value->is($server))
        ->andReturn($executor);
    app()->instance(HarnessManager::class, $manager);
    Event::fake([ChatMessageReceivedEvent::class]);

    $first = $this->actingAs($admin)
        ->postJson(route('agents.chat.server-sessions.import', [$agent, $discovery]))
        ->assertCreated();
    $second = $this->actingAs($admin)
        ->postJson(route('agents.chat.server-sessions.import', [$agent, $discovery]))
        ->assertCreated();

    $conversation = ChatConversation::query()->findOrFail($first->json('conversation.id'));
    expect($second->json('conversation.id'))->toBe($conversation->id)
        ->and($conversation->user_id)->toBe($admin->id)
        ->and($conversation->source)->toBe('openclaw')
        ->and($conversation->is_read_only)->toBeTrue()
        ->and(ChatConversation::query()->where('session_key', $discovery->session_key)->count())->toBe(1)
        ->and($conversation->messages()->count())->toBe(2)
        ->and($conversation->messages()->where('role', ChatMessageRole::User)->value('content'))
        ->toBe([['type' => 'text', 'text' => 'Can you help?']])
        ->and($conversation->messages()->where('role', ChatMessageRole::Assistant)->value('content'))
        ->toBe([['type' => 'text', 'text' => 'Yes, I can help.']]);

    $otherAdmin = User::factory()->create();
    $admin->currentTeam->members()->attach($otherAdmin, ['role' => TeamRole::Admin->value]);
    $otherAdmin->switchTeam($admin->currentTeam);
    $this->actingAs($otherAdmin)
        ->postJson(route('agents.chat.server-sessions.import', [$agent, $discovery]))
        ->assertConflict();
    $this->actingAs($otherAdmin)
        ->getJson(route('agents.chat.show', [$agent, $conversation]))
        ->assertNotFound();

    expect(ChatConversation::query()->where('session_key', $discovery->session_key)->count())->toBe(1)
        ->and($conversation->messages()->count())->toBe(2);
});

test('a same-owner retry returns an existing import without requiring the Gateway', function () {
    [$admin, $server, $agent] = openClawImportFixture();
    $conversation = ChatConversation::factory()->create([
        'agent_id' => $agent->id,
        'user_id' => $admin->id,
        'session_key' => 'agent:session-agent:slack:existing',
        'source' => 'openclaw',
        'is_read_only' => true,
        'last_reconciled_at' => null,
    ]);
    $discovery = createOpenClawDiscovery($server, $agent, $conversation->session_key);
    $discovery->forceFill([
        'claimed_by_user_id' => $admin->id,
        'chat_conversation_id' => $conversation->id,
    ])->save();

    $manager = Mockery::mock(HarnessManager::class);
    $manager->shouldNotReceive('resolveExecutor');
    $service = new OpenClawSessionSyncService(new OpenClawChatService($manager));

    $retried = $service->claimAndImport($discovery, $admin);

    expect($retried->is($conversation))->toBeTrue();

    $this->assertDatabaseHas('chat_conversations', ['id' => $conversation->id]);
    $this->assertDatabaseHas('openclaw_session_discoveries', [
        'id' => $discovery->id,
        'claimed_by_user_id' => $admin->id,
        'chat_conversation_id' => $conversation->id,
    ]);
});

test('identical OpenClaw session keys can exist safely on different agents', function () {
    [$firstAdmin, , $firstAgent] = openClawImportFixture();
    [$secondAdmin, , $secondAgent] = openClawImportFixture('second-session-daemon-token');
    $sharedKey = 'agent:session-agent:slack:thread:shared';

    $first = ChatConversation::factory()->create([
        'agent_id' => $firstAgent->id,
        'user_id' => $firstAdmin->id,
        'session_key' => $sharedKey,
    ]);
    $second = ChatConversation::factory()->create([
        'agent_id' => $secondAgent->id,
        'user_id' => $secondAdmin->id,
        'session_key' => $sharedKey,
    ]);

    expect($first->session_key)->toBe($second->session_key)
        ->and($first->agent_id)->not->toBe($second->agent_id);
});

test('session snapshots reject keys that cannot fit canonical conversations', function () {
    openClawImportFixture();

    $this->postJson('/api/daemon/session-daemon-token/chat/sessions/snapshot', [
        'sessions' => [[
            'agentId' => 'session-agent',
            'key' => str_repeat('x', 256),
            'kind' => 'direct',
        ]],
    ])->assertUnprocessable()->assertJsonValidationErrors('sessions.0.key');
});

test('session snapshots sync imported conversations only when upstream history is newer', function () {
    [$admin, $server, $agent] = openClawImportFixture();
    $conversation = ChatConversation::factory()->create([
        'agent_id' => $agent->id,
        'user_id' => $admin->id,
        'session_key' => 'agent:session-agent:slack:freshness',
        'source' => 'openclaw',
        'last_reconciled_at' => now(),
    ]);
    $discovery = createOpenClawDiscovery($server, $agent, $conversation->session_key);
    $discovery->forceFill([
        'claimed_by_user_id' => $admin->id,
        'chat_conversation_id' => $conversation->id,
    ])->save();
    Bus::fake([SyncOpenClawConversationJob::class]);

    $snapshot = fn (int $updatedAt, bool $hasActiveRun = false): array => [
        'sessions' => [[
            'agentId' => $agent->harness_agent_id,
            'key' => $conversation->session_key,
            'kind' => 'direct',
            'updatedAt' => $updatedAt,
            'hasActiveRun' => $hasActiveRun,
            'activeRunIds' => $hasActiveRun ? ['external-active-run'] : [],
        ]],
    ];

    $this->postJson(
        '/api/daemon/session-daemon-token/chat/sessions/snapshot',
        $snapshot(now()->subMinute()->getTimestampMs(), true),
    )->assertSuccessful();
    Bus::assertNotDispatched(SyncOpenClawConversationJob::class);
    expect($discovery->fresh()->has_active_run)->toBeTrue()
        ->and($discovery->fresh()->active_run_ids)->toBe(['external-active-run'])
        ->and($discovery->fresh()->discovered_at->isAfter(now()->subMinute()))->toBeTrue();

    $this->postJson(
        '/api/daemon/session-daemon-token/chat/sessions/snapshot',
        $snapshot(now()->addMinute()->getTimestampMs()),
    )->assertSuccessful();
    Bus::assertDispatched(
        SyncOpenClawConversationJob::class,
        fn (SyncOpenClawConversationJob $job) => $job->conversation->is($conversation),
    );
});

test('repeated transcript sync reuses already persisted assistant media', function () {
    Storage::fake('local');
    [$admin, $server, $agent] = openClawImportFixture();
    $discovery = createOpenClawDiscovery($server, $agent, 'agent:session-agent:webchat:media');
    $history = json_encode([
        'messages' => [[
            'role' => 'assistant',
            'content' => [[
                'type' => 'image',
                'source' => [
                    'media_type' => 'image/png',
                    'data' => base64_encode('stable-image-bytes'),
                ],
                'fileName' => 'stable.png',
            ]],
            '__openclaw' => ['id' => 'stable-media-message'],
        ]],
    ], JSON_THROW_ON_ERROR);

    $executor = Mockery::mock(CommandExecutor::class);
    $executor->shouldReceive('exec')
        ->twice()
        ->with(Mockery::on(fn (string $command) => str_contains($command, "'chat.history'")))
        ->andReturn($history);
    $manager = Mockery::mock(HarnessManager::class);
    $manager->shouldReceive('resolveExecutor')
        ->twice()
        ->withArgs(fn (Server $value) => $value->is($server))
        ->andReturn($executor);
    $service = new OpenClawSessionSyncService(new OpenClawChatService($manager));

    $conversation = $service->claimAndImport($discovery, $admin);
    $service->syncTranscript($conversation);

    expect(Storage::disk('local')->allFiles("chat-agent-media/{$conversation->id}"))
        ->toHaveCount(1)
        ->and($conversation->messages()->count())->toBe(1);
});
