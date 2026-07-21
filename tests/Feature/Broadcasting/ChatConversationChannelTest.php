<?php

use App\Broadcasting\ChatConversationChannel;
use App\Events\ChatAgentActivityEvent;
use App\Events\ChatMessageErrorEvent;
use App\Events\ChatMessageReceivedEvent;
use App\Events\ChatMessageSendingEvent;
use App\Events\ChatMessageStreamingEvent;
use App\Models\Agent;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('only the conversation owner who remains on the agent team can join its chat channel', function () {
    $owner = User::factory()->withPersonalTeam()->create();
    $team = $owner->currentTeam;
    $agent = Agent::factory()->create(['team_id' => $team->id]);
    $conversation = ChatConversation::factory()->create([
        'agent_id' => $agent->id,
        'user_id' => $owner->id,
    ]);
    $otherMember = User::factory()->create();
    $team->members()->attach($otherMember, ['role' => 'member']);
    $channel = new ChatConversationChannel;

    expect($channel->join($owner, $conversation->id))->toBeTrue()
        ->and($channel->join($otherMember, $conversation->id))->toBeFalse();

    $team->members()->detach($owner);

    expect($channel->join($owner, $conversation->id))->toBeFalse();
});

test('all chat events broadcast only on their conversation private channel', function () {
    $conversation = ChatConversation::factory()->create();
    $message = ChatMessage::factory()->assistant()->create([
        'chat_conversation_id' => $conversation->id,
    ]);
    $events = [
        new ChatMessageReceivedEvent($message),
        new ChatMessageErrorEvent($conversation->id),
        new ChatMessageSendingEvent($conversation->id, $conversation->agent_id),
        new ChatMessageStreamingEvent($conversation->id, 'stream-1', 'Hi', 'Hi', false),
        new ChatAgentActivityEvent($conversation->id, ['kind' => 'tool']),
    ];

    foreach ($events as $event) {
        $channels = $event->broadcastOn();

        expect($channels)->toHaveCount(1)
            ->and($channels[0])->toBeInstanceOf(PrivateChannel::class)
            ->and($channels[0]->name)->toBe("private-chat.conversation.{$conversation->id}");
    }
});
