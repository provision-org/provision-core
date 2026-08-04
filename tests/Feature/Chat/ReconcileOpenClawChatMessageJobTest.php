<?php

use App\Contracts\CommandExecutor;
use App\Enums\ChatMessageRole;
use App\Events\ChatMessageErrorEvent;
use App\Events\ChatMessageReceivedEvent;
use App\Jobs\CleanupOpenClawChatAttachmentsJob;
use App\Jobs\ReconcileOpenClawChatMessageJob;
use App\Models\Agent;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\Server;
use App\Models\User;
use App\Services\HarnessManager;
use App\Services\OpenClawChatService;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;

/**
 * @param  array<string, mixed>  $messageAttributes
 * @return array{Server, ChatConversation, ChatMessage}
 */
function openClawReconciliationFixture(array $messageAttributes = []): array
{
    $user = User::factory()->withPersonalTeam()->create();
    $server = Server::factory()->running()->create(['team_id' => $user->currentTeam->id]);
    $agent = Agent::factory()->create([
        'team_id' => $user->currentTeam->id,
        'server_id' => $server->id,
        'harness_agent_id' => 'reconciliation-agent',
    ]);
    $conversation = ChatConversation::factory()->create([
        'agent_id' => $agent->id,
        'user_id' => $user->id,
    ]);
    $message = ChatMessage::factory()->create([
        'chat_conversation_id' => $conversation->id,
        'role' => ChatMessageRole::User,
        'delivery_status' => 'running',
        'upstream_run_id' => 'reconciliation-run',
        ...$messageAttributes,
    ]);

    return [$server, $conversation, $message];
}

/**
 * @param  array<string, mixed>  $history
 * @param  (Closure(): void)|null  $beforeReturn
 */
function openClawReconciliationService(
    Server $server,
    array $history,
    ?Closure $beforeReturn = null,
): OpenClawChatService {
    $executor = Mockery::mock(CommandExecutor::class);
    $executor->shouldReceive('exec')
        ->once()
        ->with(Mockery::on(fn (string $command) => str_contains($command, "'chat.history'")))
        ->andReturnUsing(function () use ($beforeReturn, $history): string {
            $beforeReturn?->__invoke();

            return json_encode($history);
        });
    $manager = Mockery::mock(HarnessManager::class);
    $manager->shouldReceive('resolveExecutor')
        ->once()
        ->withArgs(fn (Server $value) => $value->is($server))
        ->andReturn($executor);

    return new OpenClawChatService($manager);
}

test('reconciliation terminally fails and cleans up after the absolute recovery window', function () {
    Bus::fake([
        CleanupOpenClawChatAttachmentsJob::class,
        ReconcileOpenClawChatMessageJob::class,
    ]);
    Event::fake([ChatMessageErrorEvent::class]);

    $user = User::factory()->withPersonalTeam()->create();
    $server = Server::factory()->running()->create(['team_id' => $user->currentTeam->id]);
    $agent = Agent::factory()->create([
        'team_id' => $user->currentTeam->id,
        'server_id' => $server->id,
    ]);
    $conversation = ChatConversation::factory()->create([
        'agent_id' => $agent->id,
        'user_id' => $user->id,
    ]);
    $message = ChatMessage::factory()->create([
        'chat_conversation_id' => $conversation->id,
        'role' => ChatMessageRole::User,
        'delivery_status' => 'running',
        'upstream_run_id' => 'long-running-gateway-run',
        'sent_at' => now()->subMinutes(61),
        'outbound_to_agent_at' => now()->subMinutes(61),
    ]);

    $manager = Mockery::mock(HarnessManager::class);
    $manager->shouldNotReceive('resolveExecutor');

    (new ReconcileOpenClawChatMessageJob($conversation, $message))
        ->handle(new OpenClawChatService($manager));

    $message->refresh();
    expect($message->delivery_status)->toBe('failed')
        ->and($message->delivery_error)->toBe('The agent did not finish this response within 60 minutes.')
        ->and($message->last_gateway_event_at)->not->toBeNull();

    Event::assertDispatched(
        ChatMessageErrorEvent::class,
        fn (ChatMessageErrorEvent $event) => $event->chatConversationId === $conversation->id
            && $event->errorMessage === $message->delivery_error,
    );
    Bus::assertDispatched(
        CleanupOpenClawChatAttachmentsJob::class,
        fn (CleanupOpenClawChatAttachmentsJob $job) => $job->conversation->is($conversation)
            && $job->message->is($message),
    );
    Bus::assertNotDispatched(ReconcileOpenClawChatMessageJob::class);
});

test('reconciliation jobs serialize per message while retaining retry capacity', function () {
    [, $conversation, $message] = openClawReconciliationFixture();
    $job = new ReconcileOpenClawChatMessageJob($conversation, $message);
    $middleware = $job->middleware();

    expect($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(WithoutOverlapping::class)
        ->and($middleware[0]->key)->toBe($job->uniqueId())
        ->and($middleware[0]->releaseAfter)->toBe(5)
        ->and($middleware[0]->expiresAfter)->toBe(120)
        ->and($job->tries)->toBe(10);
});

test('reconciliation waits for an active tool run instead of projecting an intermediate reply', function () {
    Bus::fake([
        CleanupOpenClawChatAttachmentsJob::class,
        ReconcileOpenClawChatMessageJob::class,
    ]);
    Event::fake([ChatMessageReceivedEvent::class]);
    [$server, $conversation, $message] = openClawReconciliationFixture([
        'upstream_run_id' => 'active-tool-run',
    ]);
    $chatService = openClawReconciliationService($server, [
        'messages' => [
            [
                'role' => 'user',
                'idempotencyKey' => "provision-chat:{$message->id}:user",
            ],
            [
                'role' => 'assistant',
                'content' => [['type' => 'text', 'text' => 'Still working']],
                '__openclaw' => ['id' => 'intermediate-reply', 'runId' => 'active-tool-run'],
            ],
        ],
        'sessionInfo' => [
            'hasActiveRun' => true,
            'activeRunIds' => ['active-tool-run'],
            'status' => 'running',
        ],
    ]);

    (new ReconcileOpenClawChatMessageJob($conversation, $message))->handle($chatService);

    expect($message->fresh()->delivery_status)->toBe('running')
        ->and($conversation->messages()->where('role', ChatMessageRole::Assistant)->count())->toBe(0);
    Bus::assertDispatched(
        ReconcileOpenClawChatMessageJob::class,
        fn (ReconcileOpenClawChatMessageJob $job) => $job->userMessage->is($message)
            && $job->delay !== null,
    );
    Bus::assertNotDispatched(CleanupOpenClawChatAttachmentsJob::class);
    Event::assertNotDispatched(ChatMessageReceivedEvent::class);
});

test('reconciliation recovers a canonical reply after an untyped terminal error', function () {
    Bus::fake([
        CleanupOpenClawChatAttachmentsJob::class,
        ReconcileOpenClawChatMessageJob::class,
    ]);
    Event::fake([ChatMessageReceivedEvent::class, ChatMessageErrorEvent::class]);
    [$server, $conversation, $message] = openClawReconciliationFixture([
        'delivery_error' => 'The agent encountered an error while processing this request.',
        'upstream_run_id' => 'tool-error-run',
        'last_gateway_event_at' => now(),
    ]);
    $chatService = openClawReconciliationService($server, [
        'messages' => [
            [
                'role' => 'user',
                'idempotencyKey' => "provision-chat:{$message->id}:user",
            ],
            [
                'role' => 'assistant',
                'content' => [['type' => 'text', 'text' => 'Finished after recoverable tool errors']],
                '__openclaw' => ['id' => 'canonical-final-reply', 'runId' => 'tool-error-run'],
            ],
        ],
        'sessionInfo' => [
            'hasActiveRun' => false,
            'activeRunIds' => [],
            'status' => 'done',
        ],
    ]);

    (new ReconcileOpenClawChatMessageJob($conversation, $message))->handle($chatService);

    expect($message->fresh()->delivery_status)->toBe('completed')
        ->and($message->fresh()->delivery_error)->toBeNull()
        ->and($conversation->messages()->where('upstream_id', 'openclaw:canonical-final-reply')->exists())
        ->toBeTrue();
    Event::assertDispatched(ChatMessageReceivedEvent::class);
    Event::assertNotDispatched(ChatMessageErrorEvent::class);
});

test('a canonical reply wins when a concurrent reconciler marks the message failed', function () {
    Bus::fake([CleanupOpenClawChatAttachmentsJob::class]);
    Event::fake([ChatMessageReceivedEvent::class, ChatMessageErrorEvent::class]);
    [$server, $conversation, $message] = openClawReconciliationFixture([
        'delivery_error' => 'The agent encountered an error while processing this request.',
        'upstream_run_id' => 'concurrent-reply-run',
        'last_gateway_event_at' => now()->subSeconds(11),
    ]);
    $chatService = openClawReconciliationService(
        $server,
        [
            'messages' => [
                [
                    'role' => 'user',
                    'idempotencyKey' => "provision-chat:{$message->id}:user",
                ],
                [
                    'role' => 'assistant',
                    'content' => [['type' => 'text', 'text' => 'Canonical reply wins']],
                    '__openclaw' => ['id' => 'concurrent-canonical-reply', 'runId' => 'concurrent-reply-run'],
                ],
            ],
            'sessionInfo' => [
                'hasActiveRun' => false,
                'activeRunIds' => [],
                'status' => 'done',
            ],
        ],
        function () use ($message): void {
            $message->forceFill([
                'delivery_status' => 'failed',
                'delivery_error' => 'The agent encountered an error while processing this request.',
            ])->save();
        },
    );

    (new ReconcileOpenClawChatMessageJob($conversation, $message))->handle($chatService);

    expect($message->fresh()->delivery_status)->toBe('completed')
        ->and($message->fresh()->delivery_error)->toBeNull()
        ->and($conversation->messages()->where('upstream_id', 'openclaw:concurrent-canonical-reply')->exists())
        ->toBeTrue();
    Event::assertDispatched(ChatMessageReceivedEvent::class);
    Event::assertNotDispatched(ChatMessageErrorEvent::class);
});

test('reconciliation preserves a genuine terminal error after canonical history stays empty', function () {
    Bus::fake([
        CleanupOpenClawChatAttachmentsJob::class,
        ReconcileOpenClawChatMessageJob::class,
    ]);
    Event::fake([ChatMessageErrorEvent::class]);
    $error = 'The model is temporarily rate limited. Please try again.';
    [$server, $conversation, $message] = openClawReconciliationFixture([
        'delivery_error' => $error,
        'upstream_run_id' => 'genuine-error-run',
        'last_gateway_event_at' => now()->subSeconds(11),
    ]);
    $chatService = openClawReconciliationService($server, [
        'messages' => [[
            'role' => 'user',
            'idempotencyKey' => "provision-chat:{$message->id}:user",
        ]],
        'sessionInfo' => [
            'hasActiveRun' => false,
            'activeRunIds' => [],
            'status' => 'done',
        ],
    ]);

    (new ReconcileOpenClawChatMessageJob($conversation, $message))->handle($chatService);

    expect($message->fresh()->delivery_status)->toBe('failed')
        ->and($message->fresh()->delivery_error)->toBe($error);
    Event::assertDispatched(
        ChatMessageErrorEvent::class,
        fn (ChatMessageErrorEvent $event) => $event->errorMessage === $error,
    );
    Bus::assertDispatched(CleanupOpenClawChatAttachmentsJob::class);
});

test('reconciliation gives a terminal error a brief canonical history grace period', function () {
    Bus::fake([
        CleanupOpenClawChatAttachmentsJob::class,
        ReconcileOpenClawChatMessageJob::class,
    ]);
    Event::fake([ChatMessageErrorEvent::class]);
    [$server, $conversation, $message] = openClawReconciliationFixture([
        'delivery_error' => 'The agent encountered an error while processing this request.',
        'upstream_run_id' => 'recent-error-run',
        'last_gateway_event_at' => now(),
    ]);
    $chatService = openClawReconciliationService($server, [
        'messages' => [[
            'role' => 'user',
            'idempotencyKey' => "provision-chat:{$message->id}:user",
        ]],
        'sessionInfo' => [
            'hasActiveRun' => false,
            'activeRunIds' => [],
            'status' => 'done',
        ],
    ]);

    (new ReconcileOpenClawChatMessageJob($conversation, $message))->handle($chatService);

    expect($message->fresh()->delivery_status)->toBe('running');
    Bus::assertDispatched(
        ReconcileOpenClawChatMessageJob::class,
        fn (ReconcileOpenClawChatMessageJob $job) => $job->userMessage->is($message)
            && $job->delay !== null,
    );
    Bus::assertNotDispatched(CleanupOpenClawChatAttachmentsJob::class);
    Event::assertNotDispatched(ChatMessageErrorEvent::class);
});
