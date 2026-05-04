<?php

use App\Enums\ChatMessageRole;
use App\Models\Agent;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeAgentWithWebConnection(): array
{
    $team = Team::factory()->subscribed()->create();
    $agent = Agent::factory()->create([
        'team_id' => $team->id,
        'harness_agent_id' => 'agent-test',
    ]);
    $connection = $agent->webConnection;

    return [$team, $agent, $connection];
}

function signInbound(string $body, string $secret): string
{
    $ts = (string) time();

    return 't='.$ts.',v1='.hash_hmac('sha256', $ts.'.'.$body, $secret);
}

test('agent observer auto-creates a web connection', function () {
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
