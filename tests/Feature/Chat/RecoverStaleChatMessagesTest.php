<?php

use App\Enums\AgentStatus;
use App\Enums\ChatMessageRole;
use App\Jobs\RecoverStaleChatMessagesJob;
use App\Jobs\SendAgentChatMessageJob;
use App\Models\Agent;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\Server;
use App\Models\User;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Bus;

test('stale queued messages left before enqueue are recovered exactly once', function () {
    Bus::fake([SendAgentChatMessageJob::class]);

    $conversation = ChatConversation::factory()->create();
    $stale = ChatMessage::factory()->create([
        'chat_conversation_id' => $conversation->id,
        'role' => ChatMessageRole::User,
        'delivery_status' => 'queued',
        'enqueued_at' => null,
        'sent_at' => now()->subMinutes(2),
    ]);
    $recent = ChatMessage::factory()->create([
        'chat_conversation_id' => $conversation->id,
        'role' => ChatMessageRole::User,
        'delivery_status' => 'queued',
        'enqueued_at' => null,
        'sent_at' => now()->subSeconds(30),
    ]);
    $alreadyEnqueued = ChatMessage::factory()->create([
        'chat_conversation_id' => $conversation->id,
        'role' => ChatMessageRole::User,
        'delivery_status' => 'queued',
        'enqueued_at' => now()->subSeconds(30),
        'sent_at' => now()->subMinutes(2),
    ]);
    $running = ChatMessage::factory()->create([
        'chat_conversation_id' => $conversation->id,
        'role' => ChatMessageRole::User,
        'delivery_status' => 'running',
        'enqueued_at' => null,
        'sent_at' => now()->subMinutes(2),
    ]);

    (new RecoverStaleChatMessagesJob)->handle();

    Bus::assertDispatchedTimes(SendAgentChatMessageJob::class, 1);
    Bus::assertDispatched(
        SendAgentChatMessageJob::class,
        fn (SendAgentChatMessageJob $job) => $job->userMessage->is($stale)
            && $job->conversation->is($conversation)
            && $job->queue === 'chat',
    );

    expect($stale->fresh()->enqueued_at)->not->toBeNull()
        ->and($recent->fresh()->enqueued_at)->toBeNull()
        ->and($alreadyEnqueued->fresh()->enqueued_at?->equalTo($alreadyEnqueued->enqueued_at))->toBeTrue()
        ->and($running->fresh()->enqueued_at)->toBeNull();
});

test('an expired enqueue lease is reclaimed after a sweeper crash before dispatch', function () {
    Bus::fake([SendAgentChatMessageJob::class]);

    $conversation = ChatConversation::factory()->create();
    $expiredAt = now()->subMinutes(2);
    $stale = ChatMessage::factory()->create([
        'chat_conversation_id' => $conversation->id,
        'role' => ChatMessageRole::User,
        'delivery_status' => 'queued',
        'enqueued_at' => $expiredAt,
        'sent_at' => now()->subMinutes(3),
    ]);

    (new RecoverStaleChatMessagesJob)->handle();

    Bus::assertDispatchedTimes(SendAgentChatMessageJob::class, 1);
    Bus::assertDispatched(
        SendAgentChatMessageJob::class,
        fn (SendAgentChatMessageJob $job) => $job->userMessage->is($stale),
    );
    expect($stale->fresh()->enqueued_at?->isAfter($expiredAt))->toBeTrue();

    (new RecoverStaleChatMessagesJob)->handle();

    Bus::assertDispatchedTimes(SendAgentChatMessageJob::class, 1);
});

test('a failed stale recovery releases its enqueue claim for the next sweep', function () {
    $conversation = ChatConversation::factory()->create();
    $stale = ChatMessage::factory()->create([
        'chat_conversation_id' => $conversation->id,
        'role' => ChatMessageRole::User,
        'delivery_status' => 'queued',
        'enqueued_at' => null,
        'sent_at' => now()->subMinutes(2),
    ]);
    $dispatcher = Mockery::mock(Dispatcher::class);
    $dispatcher->shouldReceive('dispatch')
        ->once()
        ->with(Mockery::type(SendAgentChatMessageJob::class))
        ->andThrow(new RuntimeException('Queue unavailable'));
    app()->instance(Dispatcher::class, $dispatcher);

    (new RecoverStaleChatMessagesJob)->handle();

    expect($stale->fresh()->delivery_status)->toBe('queued')
        ->and($stale->fresh()->enqueued_at)->toBeNull();
});

test('loading a conversation repairs the short enqueue crash window', function () {
    Bus::fake([SendAgentChatMessageJob::class]);

    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    subscribeTeam($team);
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'status' => AgentStatus::Active,
        'harness_agent_id' => 'stale-chat-agent',
    ]);
    $conversation = ChatConversation::factory()->create([
        'agent_id' => $agent->id,
        'user_id' => $user->id,
    ]);
    $message = ChatMessage::factory()->create([
        'chat_conversation_id' => $conversation->id,
        'role' => ChatMessageRole::User,
        'delivery_status' => 'queued',
        'enqueued_at' => null,
        'sent_at' => now()->subSeconds(11),
    ]);

    $this->actingAs($user)
        ->getJson(route('agents.chat.show', [$agent, $conversation]))
        ->assertSuccessful()
        ->assertJsonPath('active_run.message_id', $message->id)
        ->assertJsonPath('active_run.status', 'queued');

    Bus::assertDispatchedTimes(SendAgentChatMessageJob::class, 1);
    Bus::assertDispatched(
        SendAgentChatMessageJob::class,
        fn (SendAgentChatMessageJob $job) => $job->userMessage->is($message)
            && $job->queue === 'chat',
    );
    expect($message->fresh()->enqueued_at)->not->toBeNull();
});
