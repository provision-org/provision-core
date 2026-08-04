<?php

use App\Enums\ChatMessageRole;
use App\Events\ChatMessageErrorEvent;
use App\Jobs\CleanupOpenClawChatAttachmentsJob;
use App\Jobs\ReconcileOpenClawChatMessageJob;
use App\Models\Agent;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\Server;
use App\Models\User;
use App\Services\HarnessManager;
use App\Services\OpenClawChatService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;

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
