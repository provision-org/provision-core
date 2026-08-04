<?php

use App\Enums\ChatMessageRole;
use App\Events\ChatMessageErrorEvent;
use App\Events\ChatMessageStreamingEvent;
use App\Jobs\CleanupOpenClawChatAttachmentsJob;
use App\Jobs\ReconcileOpenClawChatMessageJob;
use App\Models\Agent;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;

/**
 * @return array{User, Server, Agent, ChatConversation, ChatMessage}
 */
function openClawRelayFixture(string $token = 'relay-daemon-token'): array
{
    $user = User::factory()->withPersonalTeam()->create();
    $server = Server::factory()->running()->create([
        'team_id' => $user->currentTeam->id,
        'daemon_token' => $token,
    ]);
    $agent = Agent::factory()->create([
        'team_id' => $user->currentTeam->id,
        'server_id' => $server->id,
        'harness_agent_id' => 'relay-agent',
    ]);
    $conversation = ChatConversation::factory()->create([
        'agent_id' => $agent->id,
        'user_id' => $user->id,
    ]);
    $conversation->forceFill([
        'session_key' => "agent:relay-agent:dashboard:{$conversation->id}",
    ])->save();
    $message = ChatMessage::factory()->create([
        'chat_conversation_id' => $conversation->id,
        'role' => ChatMessageRole::User,
        'delivery_status' => 'running',
        'upstream_run_id' => 'relay-run-1',
        'last_gateway_event_at' => now()->subMinutes(2),
    ]);

    return [$user, $server, $agent, $conversation, $message];
}

test('daemon relay accepts only monotonic deltas for the matching server run', function () {
    [, $server, $agent, $conversation, $message] = openClawRelayFixture();
    Event::fake([ChatMessageStreamingEvent::class]);
    $url = "/api/daemon/servers/{$server->id}/chat/events";

    $payload = fn (int $sequence, string $cumulative): array => [
        'events' => [[
            'event' => 'chat',
            'agent_id' => $agent->harness_agent_id,
            'session_key' => $conversation->session_key,
            'run_id' => 'relay-run-1',
            'sequence' => $sequence,
            'state' => 'delta',
            'delta' => ' world',
            'cumulative' => $cumulative,
        ]],
    ];

    $this->withToken('relay-daemon-token')->postJson($url, $payload(8, 'Hello world'))
        ->assertSuccessful()
        ->assertJsonPath('accepted', 1);
    $firstEventAt = $message->fresh()->last_gateway_event_at;

    $this->withToken('relay-daemon-token')->postJson($url, $payload(7, 'stale'))
        ->assertSuccessful();
    $this->withToken('relay-daemon-token')->postJson($url, $payload(8, 'duplicate'))
        ->assertSuccessful();

    expect($message->fresh()->upstream_event_sequence)->toBe(8)
        ->and($message->fresh()->last_gateway_event_at?->equalTo($firstEventAt))->toBeTrue();
    Event::assertDispatchedTimes(ChatMessageStreamingEvent::class, 1);
    Event::assertDispatched(
        ChatMessageStreamingEvent::class,
        fn (ChatMessageStreamingEvent $event) => $event->conversationId === $conversation->id
            && $event->streamId === 'relay-run-1'
            && $event->cumulative === 'Hello world',
    );
});

test('unknown or mismatched relay runs cannot take over an active message', function () {
    [, , $agent, $conversation, $message] = openClawRelayFixture();
    Bus::fake([ReconcileOpenClawChatMessageJob::class]);
    Event::fake([ChatMessageStreamingEvent::class]);
    $lastEventAt = $message->last_gateway_event_at;

    $this->postJson('/api/daemon/relay-daemon-token/chat/events', [
        'events' => [[
            'event' => 'chat',
            'agent_id' => $agent->harness_agent_id,
            'session_key' => $conversation->session_key,
            'run_id' => 'different-run',
            'idempotency_key' => "provision-chat:{$message->id}",
            'state' => 'final',
        ]],
    ])->assertSuccessful();

    expect($message->fresh()->upstream_run_id)->toBe('relay-run-1')
        ->and($message->fresh()->last_gateway_event_at?->equalTo($lastEventAt))->toBeTrue()
        ->and($message->fresh()->delivery_status)->toBe('running');
    Bus::assertNotDispatched(ReconcileOpenClawChatMessageJob::class);
    Event::assertNotDispatched(ChatMessageStreamingEvent::class);
});

test('relay binds a gateway run only during the pre-ack running race', function () {
    [, , $agent, $conversation, $message] = openClawRelayFixture();
    $message->forceFill([
        'upstream_run_id' => null,
        'last_gateway_event_at' => null,
    ])->save();
    Event::fake([ChatMessageStreamingEvent::class]);

    $this->postJson('/api/daemon/relay-daemon-token/chat/events', [
        'events' => [[
            'event' => 'chat',
            'agent_id' => $agent->harness_agent_id,
            'session_key' => $conversation->session_key,
            'run_id' => "provision-chat:{$message->id}",
            'sequence' => 1,
            'state' => 'delta',
            'delta' => 'Starting',
            'cumulative' => 'Starting',
        ]],
    ])->assertSuccessful();

    expect($message->fresh()->upstream_run_id)->toBe("provision-chat:{$message->id}")
        ->and($message->fresh()->upstream_event_sequence)->toBe(1)
        ->and($message->fresh()->last_gateway_event_at)->not->toBeNull();
    Event::assertDispatchedTimes(ChatMessageStreamingEvent::class, 1);
});

test('untyped gateway errors reconcile canonical history before failing the message', function () {
    [, , $agent, $conversation, $message] = openClawRelayFixture();
    Bus::fake([
        CleanupOpenClawChatAttachmentsJob::class,
        ReconcileOpenClawChatMessageJob::class,
    ]);
    Event::fake([ChatMessageErrorEvent::class]);

    $this->postJson('/api/daemon/relay-daemon-token/chat/events', [
        'events' => [[
            'event' => 'chat',
            'agent_id' => $agent->harness_agent_id,
            'session_key' => $conversation->session_key,
            'run_id' => $message->upstream_run_id,
            'sequence' => 50,
            'state' => 'error',
        ]],
    ])->assertSuccessful();

    expect($message->fresh()->delivery_status)->toBe('running')
        ->and($message->fresh()->delivery_error)
        ->toBe('The agent encountered an error while processing this request.');
    Bus::assertDispatched(
        ReconcileOpenClawChatMessageJob::class,
        fn (ReconcileOpenClawChatMessageJob $job) => $job->conversation->is($conversation)
            && $job->userMessage->is($message)
            && $job->delay !== null,
    );
    Bus::assertNotDispatched(CleanupOpenClawChatAttachmentsJob::class);
    Event::assertNotDispatched(ChatMessageErrorEvent::class);
});

test('a late event from a completed run cannot claim the next pre-ack message', function () {
    [, , $agent, $conversation, $completedMessage] = openClawRelayFixture();
    $completedMessage->forceFill(['delivery_status' => 'completed'])->save();
    $nextMessage = ChatMessage::factory()->create([
        'chat_conversation_id' => $conversation->id,
        'role' => ChatMessageRole::User,
        'delivery_status' => 'running',
        'upstream_run_id' => null,
        'last_gateway_event_at' => null,
    ]);
    Bus::fake([
        CleanupOpenClawChatAttachmentsJob::class,
        ReconcileOpenClawChatMessageJob::class,
    ]);
    Event::fake([ChatMessageErrorEvent::class]);

    $this->postJson('/api/daemon/relay-daemon-token/chat/events', [
        'events' => [[
            'event' => 'chat',
            'agent_id' => $agent->harness_agent_id,
            'session_key' => $conversation->session_key,
            'run_id' => $completedMessage->upstream_run_id,
            'state' => 'error',
        ]],
    ])->assertSuccessful();

    expect($nextMessage->fresh()->upstream_run_id)->toBeNull()
        ->and($nextMessage->fresh()->delivery_status)->toBe('running')
        ->and($nextMessage->fresh()->delivery_error)->toBeNull();
    Bus::assertNothingDispatched();
    Event::assertNotDispatched(ChatMessageErrorEvent::class);
});

test('a daemon token cannot relay events into an agent on another server', function () {
    [, $server, $agent, $conversation, $message] = openClawRelayFixture();
    $otherUser = User::factory()->withPersonalTeam()->create();
    $otherServer = Server::factory()->running()->create([
        'team_id' => $otherUser->currentTeam->id,
        'daemon_token' => 'other-daemon-token',
    ]);
    Agent::factory()->create([
        'team_id' => $otherUser->currentTeam->id,
        'server_id' => $otherServer->id,
        'harness_agent_id' => $agent->harness_agent_id,
    ]);
    Bus::fake([ReconcileOpenClawChatMessageJob::class]);

    $this->postJson('/api/daemon/other-daemon-token/chat/events', [
        'events' => [[
            'event' => 'chat',
            'agent_id' => $agent->harness_agent_id,
            'session_key' => $conversation->session_key,
            'run_id' => $message->upstream_run_id,
            'state' => 'final',
        ]],
    ])->assertSuccessful()->assertJsonPath('accepted', 0);

    expect($message->fresh()->delivery_status)->toBe('running')
        ->and($server->id)->not->toBe($otherServer->id);
    Bus::assertNotDispatched(ReconcileOpenClawChatMessageJob::class);
});

test('relay rejects invalid tokens and overlong session keys', function () {
    [, , $agent] = openClawRelayFixture();
    $payload = [
        'events' => [[
            'event' => 'chat',
            'agent_id' => $agent->harness_agent_id,
            'session_key' => str_repeat('x', 256),
            'run_id' => 'run',
            'state' => 'final',
        ]],
    ];

    $this->postJson('/api/daemon/not-the-token/chat/events', $payload)->assertUnauthorized();
    $this->postJson('/api/daemon/relay-daemon-token/chat/events', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('events.0.session_key');
});
