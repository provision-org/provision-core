<?php

use App\Models\Agent;
use App\Models\AgentEmailConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Provision\MailboxKit\MailboxKitModule;

uses(RefreshDatabase::class);

beforeEach(function () {
    if (! class_exists(MailboxKitModule::class)) {
        $this->markTestSkipped('MailboxKit module not installed');
    }
});

test('it returns message list for agent with email connection', function () {
    Bus::fake();
    Http::fake([
        '*/api/v1/inboxes/100/messages*' => Http::response([
            'data' => [
                ['id' => 1, 'from_email' => 'user@example.com', 'subject' => 'Hello', 'text_body' => 'Hi', 'created_at' => '2026-03-01T00:00:00Z'],
            ],
            'meta' => ['current_page' => 1, 'last_page' => 1],
        ]),
    ]);

    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $agent = Agent::factory()->create(['team_id' => $team->id]);
    AgentEmailConnection::factory()->create([
        'agent_id' => $agent->id,
        'mailboxkit_inbox_id' => 100,
    ]);

    $response = $this->actingAs($user)->getJson(route('agents.inbox', $agent));

    $response->assertOk()
        ->assertJsonPath('data.0.from_email', 'user@example.com')
        ->assertJsonPath('meta.current_page', 1);
});

test('it returns single message detail', function () {
    Bus::fake();
    Http::fake([
        '*/api/v1/inboxes/100/messages/5' => Http::response([
            'data' => [
                'id' => 5,
                'from_email' => 'sender@example.com',
                'to_emails' => ['agent@provisionagents.com'],
                'cc_emails' => [],
                'subject' => 'Details',
                'text_body' => 'Full body',
                'html_body' => '<p>Full body</p>',
                'attachments' => [],
                'created_at' => '2026-03-01T12:00:00Z',
            ],
        ]),
    ]);

    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $agent = Agent::factory()->create(['team_id' => $team->id]);
    AgentEmailConnection::factory()->create([
        'agent_id' => $agent->id,
        'mailboxkit_inbox_id' => 100,
    ]);

    $response = $this->actingAs($user)->getJson(route('agents.inbox.message', [$agent, 5]));

    $response->assertOk()
        ->assertJsonPath('data.from_email', 'sender@example.com')
        ->assertJsonPath('data.subject', 'Details');
});

test('it returns 404 for another team agent', function () {
    Bus::fake();
    $user = User::factory()->withPersonalTeam()->create();
    $otherUser = User::factory()->withPersonalTeam()->create();
    $agent = Agent::factory()->create(['team_id' => $otherUser->currentTeam->id]);
    AgentEmailConnection::factory()->create([
        'agent_id' => $agent->id,
        'mailboxkit_inbox_id' => 100,
    ]);

    $response = $this->actingAs($user)->getJson(route('agents.inbox', $agent));

    $response->assertNotFound();
});

test('it returns 422 for agent without email connection', function () {
    Bus::fake();
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $agent = Agent::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)->getJson(route('agents.inbox', $agent));

    $response->assertStatus(422);
});
