<?php

use App\Contracts\CommandExecutor;
use App\Enums\ChatMessageRole;
use App\Events\ChatMessageErrorEvent;
use App\Events\ChatMessageReceivedEvent;
use App\Jobs\SendAgentChatMessageJob;
use App\Models\Agent;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\Server;
use App\Models\User;
use App\Services\HarnessManager;
use App\Services\OpenClawChatService;
use Illuminate\Support\Facades\Event;

function openClawDeliveryFixture(): array
{
    $user = User::factory()->withPersonalTeam()->create();
    $server = Server::factory()->running()->create(['team_id' => $user->currentTeam->id]);
    $agent = Agent::factory()->create([
        'team_id' => $user->currentTeam->id,
        'server_id' => $server->id,
        'harness_agent_id' => 'delivery-agent',
    ]);
    $conversation = ChatConversation::factory()->create([
        'agent_id' => $agent->id,
        'user_id' => $user->id,
        'session_key' => 'web:delivery-test',
    ]);
    $message = ChatMessage::factory()->create([
        'chat_conversation_id' => $conversation->id,
        'role' => ChatMessageRole::User,
        'content' => [['type' => 'text', 'text' => 'Deliver this reliably']],
        'delivery_status' => 'queued',
    ]);

    return [$user, $server, $agent, $conversation, $message];
}

test('OpenClaw delivery job projects one canonical Gateway reply and completes the outbox row', function () {
    [, $server,, $conversation, $message] = openClawDeliveryFixture();
    Event::fake();

    $executor = Mockery::mock(CommandExecutor::class);
    $executor->shouldReceive('exec')
        ->once()
        ->with(Mockery::on(fn (string $command) => str_contains($command, "'chat.send'")))
        ->andReturn(json_encode(['runId' => 'delivery-run', 'status' => 'started']));
    $executor->shouldReceive('exec')
        ->once()
        ->with(Mockery::on(fn (string $command) => str_contains($command, "'chat.history'")))
        ->andReturn(json_encode([
            'messages' => [
                ['role' => 'user', 'idempotencyKey' => 'provision-chat:'.$message->id.':user'],
                [
                    'role' => 'assistant',
                    'content' => [['type' => 'text', 'text' => 'Canonical reply']],
                    '__openclaw' => ['id' => 'canonical-reply'],
                ],
            ],
        ]));

    $manager = Mockery::mock(HarnessManager::class);
    $manager->shouldReceive('resolveExecutor')->once()->withArgs(fn (Server $value) => $value->is($server))->andReturn($executor);

    $service = new OpenClawChatService($manager);
    (new SendAgentChatMessageJob($conversation, $message))->handle($service);
    (new SendAgentChatMessageJob($conversation, $message))->handle($service);

    $message->refresh();
    expect($message->delivery_status)->toBe('completed')
        ->and($message->upstream_run_id)->toBe('delivery-run')
        ->and($message->outbound_to_agent_at)->not->toBeNull()
        ->and($conversation->messages()->where('role', ChatMessageRole::Assistant)->count())->toBe(1)
        ->and($conversation->messages()->where('upstream_id', 'openclaw:canonical-reply')->value('content'))
        ->toBe([['type' => 'text', 'text' => 'Canonical reply']]);

    Event::assertDispatched(ChatMessageReceivedEvent::class);
});

test('conversation owner can idempotently stop an active native Gateway run', function () {
    [$user, $server, $agent, $conversation, $message] = openClawDeliveryFixture();
    $message->update([
        'delivery_status' => 'running',
        'upstream_run_id' => 'run-to-stop',
    ]);
    Event::fake();

    $executor = Mockery::mock(CommandExecutor::class);
    $executor->shouldReceive('exec')
        ->once()
        ->with(Mockery::on(fn (string $command) => str_contains($command, "'chat.abort'")
            && str_contains($command, 'run-to-stop')))
        ->andReturn(json_encode(['ok' => true, 'aborted' => true]));

    $manager = Mockery::mock(HarnessManager::class);
    $manager->shouldReceive('resolveExecutor')->once()->withArgs(fn (Server $value) => $value->is($server))->andReturn($executor);
    app()->instance(HarnessManager::class, $manager);

    $this->actingAs($user)
        ->postJson(route('agents.chat.abort', [$agent, $conversation]))
        ->assertSuccessful()
        ->assertJsonPath('aborted', true);

    expect($message->fresh()->delivery_status)->toBe('aborted')
        ->and($message->fresh()->delivery_error)->toBe('Response stopped.');
    Event::assertDispatched(ChatMessageErrorEvent::class);
});

test('another user cannot stop a conversation they do not own', function () {
    [, , $agent, $conversation] = openClawDeliveryFixture();
    $otherUser = User::factory()->withPersonalTeam()->create();
    $otherUser->teams()->attach($agent->team_id, ['role' => 'editor']);
    $otherUser->switchTeam($agent->team);

    $this->actingAs($otherUser)
        ->postJson(route('agents.chat.abort', [$agent, $conversation]))
        ->assertNotFound();
});

test('exhausted delivery persists a reload-safe error state', function () {
    [, , , $conversation, $message] = openClawDeliveryFixture();
    Event::fake();

    (new SendAgentChatMessageJob($conversation, $message))->failed(
        new RuntimeException('The agent Gateway could not be reached.'),
    );

    expect($message->fresh()->delivery_status)->toBe('failed')
        ->and($message->fresh()->delivery_error)->toBe('The agent Gateway could not be reached.');
    Event::assertDispatched(ChatMessageErrorEvent::class);
});

test('an abort racing with the canonical reply never creates or completes the assistant projection', function () {
    [, $server,, $conversation, $message] = openClawDeliveryFixture();
    Event::fake();

    $executor = Mockery::mock(CommandExecutor::class);
    $executor->shouldReceive('exec')
        ->once()
        ->with(Mockery::on(fn (string $command) => str_contains($command, "'chat.send'")))
        ->andReturn(json_encode(['runId' => 'race-run', 'status' => 'started']));
    $executor->shouldReceive('exec')
        ->once()
        ->with(Mockery::on(fn (string $command) => str_contains($command, "'chat.history'")))
        ->andReturnUsing(function () use ($message): string {
            $message->update([
                'delivery_status' => 'aborted',
                'delivery_error' => 'Response stopped.',
            ]);

            return json_encode([
                'messages' => [
                    ['role' => 'user', 'idempotencyKey' => 'provision-chat:'.$message->id.':user'],
                    [
                        'role' => 'assistant',
                        'content' => 'Too late',
                        '__openclaw' => ['id' => 'race-reply'],
                    ],
                ],
            ]);
        });

    $manager = Mockery::mock(HarnessManager::class);
    $manager->shouldReceive('resolveExecutor')->once()->withArgs(fn (Server $value) => $value->is($server))->andReturn($executor);

    (new SendAgentChatMessageJob($conversation, $message))->handle(new OpenClawChatService($manager));

    expect($message->fresh()->delivery_status)->toBe('aborted')
        ->and($conversation->messages()->where('role', ChatMessageRole::Assistant)->count())->toBe(0);
});

test('chat queue timing cannot re-reserve a still-running Horizon job', function () {
    $maxWorkerTimeout = collect(config('horizon.defaults'))->max('timeout');
    [, , , $conversation, $message] = openClawDeliveryFixture();
    $job = new SendAgentChatMessageJob($conversation, $message);

    expect(config('queue.connections.redis.retry_after'))->toBeGreaterThan($maxWorkerTimeout)
        ->and(config('horizon.defaults.supervisor-chat.queue'))->toBe(['chat'])
        ->and(config('horizon.defaults.supervisor-chat.timeout'))->toBeGreaterThan(
            $job->timeout,
        );
});
