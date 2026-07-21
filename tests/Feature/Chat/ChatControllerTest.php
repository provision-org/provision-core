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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

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
        'server_id' => Server::query()->where('team_id', $team->id)->value('id'),
        'status' => AgentStatus::Active,
        'harness_agent_id' => 'test-agent-id',
    ]);
}

test('user can view the chat page', function () {
    Bus::fake();
    $user = chatUser();
    $agent = chatAgent($user->currentTeam);
    $agent->update([
        'api_server_key' => 'gateway-secret',
        'config_snapshot' => ['gateway' => ['auth' => ['token' => 'snapshot-secret']]],
        'default_password' => 'default-secret',
    ]);

    $response = $this->actingAs($user)->get(route('agents.chat', $agent));

    $response->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->missing('agent.api_server_key')
            ->missing('agent.config_snapshot')
            ->missing('agent.default_password'));
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

test('new conversation retries reuse one durable client message', function () {
    Bus::fake();
    $user = chatUser();
    $agent = chatAgent($user->currentTeam);
    $payload = [
        'content' => 'Send this only once',
        'client_message_id' => 'client-retry-new-conversation',
    ];

    $first = $this->actingAs($user)->postJson(route('agents.chat.store', $agent), $payload);
    $second = $this->actingAs($user)->postJson(route('agents.chat.store', $agent), $payload);

    $first->assertCreated();
    $second->assertSuccessful()->assertJsonPath('idempotent_replay', true);

    expect($second->json('conversation.id'))->toBe($first->json('conversation.id'))
        ->and($second->json('message.id'))->toBe($first->json('message.id'))
        ->and(ChatConversation::query()->where('agent_id', $agent->id)->count())->toBe(1)
        ->and(ChatMessage::query()->where('client_message_id', $payload['client_message_id'])->count())->toBe(1);

    Bus::assertDispatchedTimes(SendAgentChatMessageJob::class, 1);
    Bus::assertDispatched(
        SendAgentChatMessageJob::class,
        fn (SendAgentChatMessageJob $job) => $job->queue === 'chat',
    );
});

test('stream retries reuse one message while distinct concurrent sends are rejected', function () {
    Bus::fake();
    $user = chatUser();
    $agent = chatAgent($user->currentTeam);
    $conversation = ChatConversation::factory()->create([
        'agent_id' => $agent->id,
        'user_id' => $user->id,
    ]);
    $payload = [
        'content' => 'A durable streamed turn',
        'client_message_id' => 'client-retry-stream',
    ];

    $first = $this->actingAs($user)->post(route('agents.chat.stream', [$agent, $conversation]), $payload);
    expect($first->streamedContent())->toContain('event: message')->toContain('event: handoff');

    $second = $this->actingAs($user)->post(route('agents.chat.stream', [$agent, $conversation]), $payload);
    expect($second->streamedContent())->toContain('event: message')->toContain('durable-replay');

    $this->actingAs($user)->postJson(route('agents.chat.send', [$agent, $conversation]), [
        'content' => 'A conflicting second turn',
        'client_message_id' => 'client-distinct-while-active',
    ])->assertConflict();

    expect($conversation->messages()->where('role', ChatMessageRole::User)->count())->toBe(1);
    Bus::assertDispatchedTimes(SendAgentChatMessageJob::class, 1);
});

test('client message identifiers cannot be replayed across conversation owners', function () {
    Bus::fake();
    $user = chatUser();
    $agent = chatAgent($user->currentTeam);
    $clientMessageId = 'client-owner-isolation';

    $this->actingAs($user)->postJson(route('agents.chat.store', $agent), [
        'content' => 'Owner message',
        'client_message_id' => $clientMessageId,
    ])->assertCreated();

    $otherUser = User::factory()->create();
    $otherUser->teams()->attach($user->currentTeam->id, ['role' => 'editor']);
    $otherUser->switchTeam($user->currentTeam);

    $this->actingAs($otherUser)->postJson(route('agents.chat.store', $agent), [
        'content' => 'Collision attempt',
        'client_message_id' => $clientMessageId,
    ])->assertConflict();

    expect(ChatMessage::query()->where('client_message_id', $clientMessageId)->count())->toBe(1);
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

test('user can create an attachment-only conversation without overwriting duplicate filenames', function () {
    Storage::fake('local');
    Bus::fake();
    $user = chatUser();
    $agent = chatAgent($user->currentTeam);

    $response = $this->actingAs($user)->post(route('agents.chat.store', $agent), [
        'attachments' => [
            UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
            UploadedFile::fake()->create('notes.txt', 12, 'text/plain'),
        ],
    ]);

    $response->assertCreated();
    $conversation = ChatConversation::findOrFail($response->json('conversation.id'));
    $message = $conversation->messages()->firstOrFail();
    $paths = collect($message->content)->pluck('path');

    expect($conversation->title)->toBe('notes.txt')
        ->and($message->content)->toHaveCount(2)
        ->and(collect($message->content)->pluck('type')->all())->toBe(['file', 'file'])
        ->and(collect($message->content)->pluck('fileName')->all())->toBe(['notes.txt', 'notes.txt'])
        ->and($paths->unique())->toHaveCount(2);

    $paths->each(fn (string $path) => Storage::disk('local')->assertExists($path));
    Bus::assertDispatched(SendAgentChatMessageJob::class);
});

test('user attachment URLs expire', function () {
    Storage::fake('local');
    Bus::fake();
    $user = chatUser();
    $agent = chatAgent($user->currentTeam);

    $response = $this->actingAs($user)->post(route('agents.chat.store', $agent), [
        'attachments' => [UploadedFile::fake()->create('notes.txt', 10, 'text/plain')],
    ]);

    $response->assertCreated();
    $url = $response->json('message.content.0.url');

    expect($url)->toContain('expires=')->toContain('signature=');
    $this->get($url)->assertSuccessful();

    $this->travel(16)->minutes();
    $this->get($url)->assertForbidden();
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
