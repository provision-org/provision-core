<?php

use App\Models\Agent;
use App\Models\AgentEmailConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Provision\MailboxKit\MailboxKitModule;

uses(RefreshDatabase::class);

beforeEach(function () {
    if (! class_exists(MailboxKitModule::class)) {
        $this->markTestSkipped('MailboxKit module not installed');
    }
});

test('valid signature returns 200 ok', function () {
    $agent = Agent::factory()->create();
    $secret = 'test-webhook-secret';

    AgentEmailConnection::factory()->create([
        'agent_id' => $agent->id,
        'mailboxkit_inbox_id' => 100,
        'mailboxkit_webhook_id' => 'wh-1',
        'mailboxkit_webhook_secret' => $secret,
    ]);

    $payload = json_encode([
        'event' => 'message.received',
        'inbox_id' => 100,
        'data' => ['id' => 1, 'subject' => 'Hello'],
    ]);

    $signature = hash_hmac('sha256', $payload, $secret);

    $response = $this->postJson(
        route('api.webhooks.mailboxkit'),
        json_decode($payload, true),
        [
            'X-Signature' => $signature,
            'Content-Type' => 'application/json',
        ],
    );

    $response->assertOk()
        ->assertJson(['status' => 'ok']);
});

test('invalid signature returns 403', function () {
    $agent = Agent::factory()->create();

    AgentEmailConnection::factory()->create([
        'agent_id' => $agent->id,
        'mailboxkit_inbox_id' => 200,
        'mailboxkit_webhook_id' => 'wh-2',
        'mailboxkit_webhook_secret' => 'real-secret',
    ]);

    $payload = [
        'event' => 'message.received',
        'inbox_id' => 200,
        'data' => ['id' => 2],
    ];

    $response = $this->postJson(
        route('api.webhooks.mailboxkit'),
        $payload,
        ['X-Signature' => 'wrong-signature'],
    );

    $response->assertStatus(403);
});

test('missing signature returns 403 when secret is set', function () {
    $agent = Agent::factory()->create();

    AgentEmailConnection::factory()->create([
        'agent_id' => $agent->id,
        'mailboxkit_inbox_id' => 300,
        'mailboxkit_webhook_id' => 'wh-3',
        'mailboxkit_webhook_secret' => 'some-secret',
    ]);

    $response = $this->postJson(
        route('api.webhooks.mailboxkit'),
        ['event' => 'message.received', 'inbox_id' => 300, 'data' => ['id' => 3]],
    );

    $response->assertStatus(403);
});

test('unknown inbox id returns 200 gracefully', function () {
    $response = $this->postJson(
        route('api.webhooks.mailboxkit'),
        ['event' => 'message.received', 'inbox_id' => 99999, 'data' => ['id' => 4]],
    );

    $response->assertOk()
        ->assertJson(['status' => 'ok']);
});

test('missing inbox id returns 200 gracefully', function () {
    $response = $this->postJson(
        route('api.webhooks.mailboxkit'),
        ['event' => 'message.received', 'data' => ['id' => 5]],
    );

    $response->assertOk()
        ->assertJson(['status' => 'ok']);
});

test('connection without secret accepts any request', function () {
    $agent = Agent::factory()->create();

    AgentEmailConnection::factory()->create([
        'agent_id' => $agent->id,
        'mailboxkit_inbox_id' => 400,
        'mailboxkit_webhook_id' => null,
        'mailboxkit_webhook_secret' => null,
    ]);

    $response = $this->postJson(
        route('api.webhooks.mailboxkit'),
        ['event' => 'message.received', 'inbox_id' => 400, 'data' => ['id' => 6]],
    );

    $response->assertOk()
        ->assertJson(['status' => 'ok']);
});
