<?php

use App\Enums\ChatMessageRole;
use App\Models\Agent;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\Server;
use App\Models\User;
use App\Services\DaemonChatSendService;
use App\Services\OpenClawChatService;
use Illuminate\Support\Facades\Redis;

/**
 * @return array{Server, ChatConversation, ChatMessage}
 */
function fastSendFixture(array $serverOverrides = []): array
{
    $user = User::factory()->withPersonalTeam()->create();
    $server = Server::factory()->running()->create(array_merge([
        'team_id' => $user->currentTeam->id,
        'daemon_token' => 'fast-send-daemon-token',
        'daemon_capabilities' => ['chat-relay-v1', 'chat-send-v1', 'session-discovery-v1'],
        'last_health_check' => now(),
    ], $serverOverrides));
    $agent = Agent::factory()->create([
        'team_id' => $user->currentTeam->id,
        'server_id' => $server->id,
        'harness_agent_id' => 'fast-agent',
    ]);
    $conversation = ChatConversation::factory()->create([
        'agent_id' => $agent->id,
        'user_id' => $user->id,
    ]);
    $message = ChatMessage::factory()->create([
        'chat_conversation_id' => $conversation->id,
        'role' => ChatMessageRole::User,
        'delivery_status' => 'queued',
        'content' => [['type' => 'text', 'text' => 'hello from fast send']],
    ]);

    Redis::connection()->client()->del(DaemonChatSendService::outboxKey($server));
    Redis::connection()->client()->del(DaemonChatSendService::ackKey($message));

    return [$server, $conversation, $message];
}

function fastSendService(int $ackTimeout = 1): DaemonChatSendService
{
    return new DaemonChatSendService(app(OpenClawChatService::class), $ackTimeout);
}

test('fast send succeeds when the daemon acks and marks the message running', function () {
    [$server, $conversation, $message] = fastSendFixture();

    // Pre-load the ack the daemon would post after firing chat.send.
    Redis::connection()->client()->rpush(
        DaemonChatSendService::ackKey($message),
        json_encode(['status' => 'started', 'run_id' => "provision-chat:{$message->id}"]),
    );

    expect(fastSendService()->attempt($conversation, $message))->toBeTrue();

    $entry = json_decode(
        (string) Redis::connection()->client()->lpop(DaemonChatSendService::outboxKey($server)),
        true,
    );
    expect($entry['message'])->toBe('hello from fast send')
        ->and($entry['idempotency_key'])->toBe("provision-chat:{$message->id}")
        ->and($entry['agent_id'])->toBe('fast-agent')
        ->and($entry['session_key'])->toStartWith('agent:fast-agent:dashboard:');

    expect($message->fresh())
        ->delivery_status->toBe('running')
        ->upstream_run_id->toBe("provision-chat:{$message->id}");
});

test('fast send falls back and re-queues the claim when no ack arrives', function () {
    [, $conversation, $message] = fastSendFixture();

    expect(fastSendService()->attempt($conversation, $message))->toBeFalse();

    // The claim is released so the SSH path's own CAS can re-claim.
    expect($message->fresh())->delivery_status->toBe('queued');
});

test('fast send is skipped without the daemon capability', function () {
    [$server, $conversation, $message] = fastSendFixture([
        'daemon_capabilities' => ['chat-relay-v1'],
    ]);

    expect(fastSendService()->attempt($conversation, $message))->toBeFalse()
        ->and(Redis::connection()->client()->llen(DaemonChatSendService::outboxKey($server)))->toBe(0)
        ->and($message->fresh()->delivery_status)->toBe('queued');
});

test('fast send is skipped for stale daemon heartbeats and attachment messages', function () {
    [$server, $conversation, $message] = fastSendFixture([
        'last_health_check' => now()->subMinutes(10),
    ]);

    expect(fastSendService()->attempt($conversation, $message))->toBeFalse();

    $server->forceFill(['last_health_check' => now()])->save();
    $message->forceFill(['content' => [
        ['type' => 'text', 'text' => 'see attachment'],
        ['type' => 'image', 'mimeType' => 'image/png', 'path' => 'chat/x.png'],
    ]])->save();

    expect(fastSendService()->attempt($conversation, $message->fresh()))->toBeFalse()
        ->and(Redis::connection()->client()->llen(DaemonChatSendService::outboxKey($server)))->toBe(0);
});

test('the daemon can poll the outbox and ack a send', function () {
    [$server, $conversation, $message] = fastSendFixture();

    Redis::connection()->client()->rpush(DaemonChatSendService::outboxKey($server), json_encode([
        'message_id' => $message->id,
        'session_key' => 'agent:fast-agent:dashboard:c1',
        'agent_id' => 'fast-agent',
        'message' => 'hello',
        'idempotency_key' => "provision-chat:{$message->id}",
        'queued_at' => now()->getTimestampMs(),
    ]));

    $poll = $this->withToken('fast-send-daemon-token')
        ->getJson("/api/daemon/servers/{$server->id}/chat/outbox?wait=1");

    $poll->assertOk()->assertJsonPath('send.message_id', $message->id);

    $this->withToken('fast-send-daemon-token')
        ->postJson("/api/daemon/servers/{$server->id}/chat/outbox/ack", [
            'message_id' => $message->id,
            'status' => 'started',
            'run_id' => "provision-chat:{$message->id}",
        ])
        ->assertOk();

    $ack = Redis::connection()->client()->blpop([DaemonChatSendService::ackKey($message)], 1);
    expect(json_decode((string) $ack[1], true)['status'])->toBe('started');
});

test('the outbox poll skips entries older than the fallback TTL', function () {
    [$server, , $message] = fastSendFixture();

    Redis::connection()->client()->rpush(DaemonChatSendService::outboxKey($server), json_encode([
        'message_id' => $message->id,
        'session_key' => 'agent:fast-agent:dashboard:c1',
        'agent_id' => 'fast-agent',
        'message' => 'stale entry',
        'idempotency_key' => "provision-chat:{$message->id}",
        'queued_at' => now()->subMinute()->getTimestampMs(),
    ]));

    $this->withToken('fast-send-daemon-token')
        ->getJson("/api/daemon/servers/{$server->id}/chat/outbox?wait=1")
        ->assertOk()
        ->assertJsonPath('send', null);
});

test('an ack for another server\'s message is rejected', function () {
    [, , $message] = fastSendFixture();
    [$otherServer] = fastSendFixture(['daemon_token' => 'other-daemon-token']);

    $this->withToken('other-daemon-token')
        ->postJson("/api/daemon/servers/{$otherServer->id}/chat/outbox/ack", [
            'message_id' => $message->id,
            'status' => 'started',
        ])
        ->assertNotFound();
});
