<?php

use App\Enums\ChatMessageRole;
use App\Models\Agent;
use App\Models\AgentWebConnection;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeAgentWithWebConnection(): array
{
    $team = Team::factory()->subscribed()->create();
    $agent = Agent::factory()->create([
        'team_id' => $team->id,
        'harness_agent_id' => 'agent-test',
    ]);
    $connection = AgentWebConnection::provisionFor($agent);

    return [$team, $agent, $connection];
}

function signInbound(string $body, string $secret): string
{
    $ts = (string) time();

    return 't='.$ts.',v1='.hash_hmac('sha256', $ts.'.'.$body, $secret);
}

test('legacy web connections can still be provisioned explicitly during rollout', function () {
    [$team, $agent, $connection] = makeAgentWithWebConnection();

    expect($connection)->not->toBeNull()
        ->and($connection->account_id)->toBe('provision-web-agent-test')
        ->and($connection->webhook_secret)->not->toBeEmpty()
        ->and($connection->api_token)->not->toBeEmpty();
});

test('inbound creates a new conversation and assistant message', function () {
    [, $agent, $connection] = makeAgentWithWebConnection();

    $body = json_encode([
        'accountId' => $connection->account_id,
        'kind' => 'text',
        'text' => 'I just finished onboarding!',
    ]);
    $signature = signInbound($body, $connection->webhook_secret);

    $response = $this->call(
        'POST',
        '/api/agents/web-channel/inbound',
        [], [], [],
        ['HTTP_X-Provision-Signature' => $signature, 'CONTENT_TYPE' => 'application/json'],
        $body,
    );

    $response->assertSuccessful();
    $response->assertJsonStructure(['messageId', 'conversationId']);

    $conversation = ChatConversation::where('agent_id', $agent->id)->first();
    expect($conversation)->not->toBeNull();

    $message = ChatMessage::where('chat_conversation_id', $conversation->id)->first();
    expect($message->role)->toBe(ChatMessageRole::Assistant)
        ->and($message->textContent())->toBe('I just finished onboarding!');
});

test('inbound rejects bad signature', function () {
    [,, $connection] = makeAgentWithWebConnection();

    $body = json_encode(['accountId' => $connection->account_id, 'kind' => 'text', 'text' => 'hi']);

    $response = $this->call(
        'POST',
        '/api/agents/web-channel/inbound',
        [], [], [],
        ['HTTP_X-Provision-Signature' => 't='.time().',v1=deadbeef', 'CONTENT_TYPE' => 'application/json'],
        $body,
    );

    $response->assertStatus(403);
});

test('inbound rejects expired signature', function () {
    [,, $connection] = makeAgentWithWebConnection();

    $body = json_encode(['accountId' => $connection->account_id, 'kind' => 'text', 'text' => 'hi']);
    $oldTs = (string) (time() - 3600);
    $signature = 't='.$oldTs.',v1='.hash_hmac('sha256', $oldTs.'.'.$body, $connection->webhook_secret);

    $response = $this->call(
        'POST',
        '/api/agents/web-channel/inbound',
        [], [], [],
        ['HTTP_X-Provision-Signature' => $signature, 'CONTENT_TYPE' => 'application/json'],
        $body,
    );

    $response->assertStatus(403);
});

test('inbound rejects unknown account', function () {
    $body = json_encode(['accountId' => 'provision-web-nope', 'kind' => 'text', 'text' => 'hi']);
    $signature = 't='.time().',v1='.hash_hmac('sha256', time().'.'.$body, 'whatever');

    $response = $this->call(
        'POST',
        '/api/agents/web-channel/inbound',
        [], [], [],
        ['HTTP_X-Provision-Signature' => $signature, 'CONTENT_TYPE' => 'application/json'],
        $body,
    );

    $response->assertStatus(404);
});

test('inbound preserves an existing conversation when conversationId is given', function () {
    [, $agent, $connection] = makeAgentWithWebConnection();

    $existing = ChatConversation::create([
        'agent_id' => $agent->id,
        'user_id' => $agent->team->user_id,
        'session_key' => 'web:'.Str::ulid()->toBase32(),
    ]);

    $body = json_encode([
        'accountId' => $connection->account_id,
        'conversationId' => $existing->id,
        'kind' => 'text',
        'text' => 'follow-up reply',
    ]);
    $signature = signInbound($body, $connection->webhook_secret);

    $response = $this->call(
        'POST',
        '/api/agents/web-channel/inbound',
        [], [], [],
        ['HTTP_X-Provision-Signature' => $signature, 'CONTENT_TYPE' => 'application/json'],
        $body,
    );

    $response->assertSuccessful();
    $response->assertJsonPath('conversationId', $existing->id);
    expect(ChatConversation::where('agent_id', $agent->id)->count())->toBe(1);
});

test('inbound rejects a provided conversation that does not belong to the account agent', function () {
    [, $agent, $connection] = makeAgentWithWebConnection();
    $otherTeam = Team::factory()->subscribed()->create();
    $otherAgent = Agent::factory()->create([
        'team_id' => $otherTeam->id,
        'harness_agent_id' => 'agent-other',
    ]);
    $otherConversation = ChatConversation::factory()->create([
        'agent_id' => $otherAgent->id,
        'user_id' => $otherAgent->team->user_id,
    ]);

    $body = json_encode([
        'accountId' => $connection->account_id,
        'conversationId' => $otherConversation->id,
        'kind' => 'text',
        'text' => 'misrouted reply',
        'replyToId' => 'upstream-1',
    ]);

    $response = $this->call(
        'POST',
        '/api/agents/web-channel/inbound',
        [], [], [],
        ['HTTP_X-Provision-Signature' => signInbound($body, $connection->webhook_secret), 'CONTENT_TYPE' => 'application/json'],
        $body,
    );

    $response->assertNotFound()->assertJsonPath('error', 'unknown_conversation');
    expect(ChatConversation::where('agent_id', $agent->id)->count())->toBe(0);
});

test('inbound retries with the same reply identifier are idempotent', function () {
    [, $agent, $connection] = makeAgentWithWebConnection();
    $conversation = ChatConversation::factory()->create([
        'agent_id' => $agent->id,
        'user_id' => $agent->team->user_id,
    ]);

    $body = json_encode([
        'accountId' => $connection->account_id,
        'conversationId' => $conversation->id,
        'kind' => 'text',
        'text' => 'one durable reply',
        'replyToId' => 'openclaw-reply-123',
    ]);
    $server = [
        'HTTP_X-Provision-Signature' => signInbound($body, $connection->webhook_secret),
        'CONTENT_TYPE' => 'application/json',
    ];

    $first = $this->call('POST', '/api/agents/web-channel/inbound', [], [], [], $server, $body);
    $second = $this->call('POST', '/api/agents/web-channel/inbound', [], [], [], $server, $body);

    $first->assertSuccessful();
    $second->assertSuccessful()
        ->assertJsonPath('messageId', $first->json('messageId'))
        ->assertJsonPath('duplicate', true);

    expect(ChatMessage::query()
        ->where('chat_conversation_id', $conversation->id)
        ->where('upstream_id', 'openclaw-reply-123')
        ->count())->toBe(1);
});

test('inbound resolves a missing conversation from the replied-to user message', function () {
    [, $agent, $connection] = makeAgentWithWebConnection();
    $conversation = ChatConversation::factory()->create([
        'agent_id' => $agent->id,
        'user_id' => $agent->team->user_id,
    ]);
    $userMessage = ChatMessage::factory()->create([
        'chat_conversation_id' => $conversation->id,
        'role' => ChatMessageRole::User,
    ]);

    $body = json_encode([
        'accountId' => $connection->account_id,
        'kind' => 'text',
        'text' => 'reply without an explicit conversation',
        'replyToId' => $userMessage->id,
    ]);

    $response = $this->call(
        'POST',
        '/api/agents/web-channel/inbound',
        [], [], [],
        ['HTTP_X-Provision-Signature' => signInbound($body, $connection->webhook_secret), 'CONTENT_TYPE' => 'application/json'],
        $body,
    );

    $response->assertSuccessful()->assertJsonPath('conversationId', $conversation->id);
    expect(ChatConversation::where('agent_id', $agent->id)->count())->toBe(1);
});

test('inbound persists a durable storage key for media uploaded through the account', function () {
    Storage::fake('r2');
    [, $agent, $connection] = makeAgentWithWebConnection();
    $conversation = ChatConversation::factory()->create([
        'agent_id' => $agent->id,
        'user_id' => $agent->team->user_id,
    ]);
    $path = "web-channel/{$agent->team_id}/{$connection->account_id}/image.png";
    Storage::disk('r2')->put($path, 'image-bytes');

    $body = json_encode([
        'accountId' => $connection->account_id,
        'conversationId' => $conversation->id,
        'kind' => 'media',
        'mediaUrl' => "https://r2.example.test/{$path}?X-Amz-Expires=300",
        'mediaMime' => 'image/png',
        'replyToId' => 'media-reply-1',
    ]);

    $response = $this->call(
        'POST',
        '/api/agents/web-channel/inbound',
        [], [], [],
        ['HTTP_X-Provision-Signature' => signInbound($body, $connection->webhook_secret), 'CONTENT_TYPE' => 'application/json'],
        $body,
    );

    $response->assertSuccessful();
    $message = ChatMessage::findOrFail($response->json('messageId'));

    expect($message->content[0])
        ->toMatchArray([
            'type' => 'image',
            'disk' => 'r2',
            'path' => $path,
            'mimeType' => 'image/png',
        ]);
});

test('probe rejects bad bearer', function () {
    [,, $connection] = makeAgentWithWebConnection();

    $response = $this->withHeaders(['Authorization' => 'Bearer wrong'])
        ->get("/api/agents/web-channel/{$connection->account_id}/probe");

    $response->assertStatus(401);
});

test('probe accepts valid bearer', function () {
    [, $agent, $connection] = makeAgentWithWebConnection();

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$connection->api_token])
        ->get("/api/agents/web-channel/{$connection->account_id}/probe");

    $response->assertSuccessful();
    $response->assertJson([
        'ok' => true,
        'accountId' => $connection->account_id,
        'agentId' => $agent->harness_agent_id,
    ]);
});

test('probe rejects unknown account', function () {
    $response = $this->withHeaders(['Authorization' => 'Bearer x'])
        ->get('/api/agents/web-channel/provision-web-unknown/probe');

    $response->assertStatus(404);
});
