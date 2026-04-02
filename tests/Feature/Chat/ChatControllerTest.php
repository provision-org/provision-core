<?php

use App\Enums\AgentStatus;
use App\Enums\ChatMessageRole;
use App\Jobs\SendAgentChatMessageJob;
use App\Models\Agent;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

function chatUser(): User
{
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;

    Server::factory()->running()->create(['team_id' => $team->id]);

    subscribeTeam($team);

    return $user;
}

function chatAgent(Team $team): Agent
{
    return Agent::factory()->create([
        'team_id' => $team->id,
        'status' => AgentStatus::Active,
        'harness_agent_id' => 'test-agent-id',
    ]);
}

test('user can view the chat page', function () {
    Bus::fake();
    $user = chatUser();
    $agent = chatAgent($user->currentTeam);

    $response = $this->actingAs($user)->get(route('agents.chat', $agent));

    $response->assertSuccessful();
});

test('chat page lists conversations for the authenticated user', function () {
    Bus::fake();
    $user = chatUser();
    $agent = chatAgent($user->currentTeam);

    $c1 = ChatConversation::factory()->create([
        'agent_id' => $agent->id,
        'user_id' => $user->id,
        'title' => 'My conversation',
    ]);

    $otherUser = User::factory()->create();
    ChatConversation::factory()->create([
        'agent_id' => $agent->id,
        'user_id' => $otherUser->id,
        'title' => 'Other user conversation',
    ]);

    $response = $this->actingAs($user)->get(route('agents.chat', $agent));

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->has('conversations', 1)
        ->where('conversations.0.id', $c1->id));
});

test('user can create a new conversation with first message', function () {
    Bus::fake();
    $user = chatUser();
    $agent = chatAgent($user->currentTeam);

    $response = $this->actingAs($user)->postJson(route('agents.chat.store', $agent), [
        'content' => 'Hello agent!',
    ]);

    $response->assertCreated();

    $data = $response->json();
    expect($data['conversation'])->toHaveKey('id')
        ->and($data['message']['role'])->toBe('user')
        ->and($data['message']['content'][0]['text'])->toBe('Hello agent!');

    $this->assertDatabaseHas('chat_conversations', [
        'agent_id' => $agent->id,
        'user_id' => $user->id,
    ]);

    $this->assertDatabaseHas('chat_messages', [
        'role' => ChatMessageRole::User->value,
    ]);

    Bus::assertDispatched(SendAgentChatMessageJob::class);
});

test('user can send a message to an existing conversation', function () {
    Bus::fake();
    $user = chatUser();
    $agent = chatAgent($user->currentTeam);

    $conversation = ChatConversation::factory()->create([
        'agent_id' => $agent->id,
        'user_id' => $user->id,
    ]);

    $response = $this->actingAs($user)->postJson(route('agents.chat.send', [$agent, $conversation]), [
        'content' => 'Follow up message',
    ]);

    $response->assertSuccessful();

    expect($response->json('message.content.0.text'))->toBe('Follow up message');

    Bus::assertDispatched(SendAgentChatMessageJob::class);
});

test('user can view messages in a conversation', function () {
    Bus::fake();
    $user = chatUser();
    $agent = chatAgent($user->currentTeam);

    $conversation = ChatConversation::factory()->create([
        'agent_id' => $agent->id,
        'user_id' => $user->id,
    ]);

    ChatMessage::factory()->create([
        'chat_conversation_id' => $conversation->id,
        'role' => ChatMessageRole::User,
        'content' => [['type' => 'text', 'text' => 'Hello']],
    ]);

    ChatMessage::factory()->assistant()->create([
        'chat_conversation_id' => $conversation->id,
        'content' => [['type' => 'text', 'text' => 'Hi there!']],
    ]);

    $response = $this->actingAs($user)->getJson(route('agents.chat.show', [$agent, $conversation]));

    $response->assertSuccessful();
    expect($response->json('messages'))->toHaveCount(2);
});

test('user cannot view another user conversation', function () {
    Bus::fake();
    $user = chatUser();
    $agent = chatAgent($user->currentTeam);
    $otherUser = User::factory()->create();

    $conversation = ChatConversation::factory()->create([
        'agent_id' => $agent->id,
        'user_id' => $otherUser->id,
    ]);

    $response = $this->actingAs($user)->getJson(route('agents.chat.show', [$agent, $conversation]));

    $response->assertNotFound();
});

test('user cannot chat with agent from another team', function () {
    Bus::fake();
    $user = chatUser();
    $otherTeam = Team::factory()->subscribed()->create();
    $otherAgent = Agent::factory()->create([
        'team_id' => $otherTeam->id,
        'status' => AgentStatus::Active,
    ]);

    $response = $this->actingAs($user)->get(route('agents.chat', $otherAgent));

    $response->assertNotFound();
});

test('message content is required', function () {
    Bus::fake();
    $user = chatUser();
    $agent = chatAgent($user->currentTeam);

    $response = $this->actingAs($user)->postJson(route('agents.chat.store', $agent), [
        'content' => '',
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('content');
});

test('message content has max length', function () {
    Bus::fake();
    $user = chatUser();
    $agent = chatAgent($user->currentTeam);

    $response = $this->actingAs($user)->postJson(route('agents.chat.store', $agent), [
        'content' => str_repeat('a', 10001),
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('content');
});

test('user cannot send message to conversation they do not own', function () {
    Bus::fake();
    $user = chatUser();
    $agent = chatAgent($user->currentTeam);
    $otherUser = User::factory()->create();

    $conversation = ChatConversation::factory()->create([
        'agent_id' => $agent->id,
        'user_id' => $otherUser->id,
    ]);

    $response = $this->actingAs($user)->postJson(route('agents.chat.send', [$agent, $conversation]), [
        'content' => 'Trying to sneak in',
    ]);

    $response->assertNotFound();
});

test('conversations endpoint returns paginated results', function () {
    Bus::fake();
    $user = chatUser();
    $agent = chatAgent($user->currentTeam);

    ChatConversation::factory()->count(3)->create([
        'agent_id' => $agent->id,
        'user_id' => $user->id,
    ]);

    $response = $this->actingAs($user)->getJson(route('agents.chat.conversations', $agent));

    $response->assertSuccessful();
    expect($response->json('data'))->toHaveCount(3);
});
