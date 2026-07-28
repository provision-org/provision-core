<?php

use App\Models\Agent;
use App\Models\AgentEmailConnection;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\AgentInstallScriptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

function gmailAgent(Team $team, Server $server): Agent
{
    $agent = Agent::factory()->deploying()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'harness_agent_id' => 'agent-gmail-test',
        'system_prompt' => 'You are helpful.',
    ]);
    AgentEmailConnection::factory()->create([
        'agent_id' => $agent->id,
        'provider' => 'gmail',
        'email_address' => 'care@ruhcare.com',
        'app_password' => 'abcdabcdabcdabcd',
    ]);

    return $agent->fresh();
}

// --- Install script (agent-create path) ---

test('install script deploys the gmail skill, cli and per-agent IDLE watcher service', function () {
    $team = Team::factory()->starterPlan()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = gmailAgent($team, $server);

    $script = app(AgentInstallScriptService::class)->generateScript($agent);

    expect($script)
        ->toContain('/skills/gmail/SKILL.md')
        ->toContain('/skills/gmail/gmail_cli.py')
        ->toContain('gmail_idle_watcher.py')
        // Per-agent systemd watcher service, keyed by the agent id.
        ->toContain('gmail-watcher-agent-gmail-test.service')
        ->toContain('systemctl --user enable --now gmail-watcher-agent-gmail-test.service')
        // Watcher reads creds from the agent .env, not the unit file.
        ->toContain('EnvironmentFile=/root/.openclaw/agents/agent-gmail-test/.env')
        // Per-agent env carries the Gmail account (address + app password + cli path).
        ->toContain('GMAIL_ADDRESS=care@ruhcare.com')
        ->toContain('GMAIL_APP_PASSWORD=abcdabcdabcdabcd')
        ->toContain('GMAIL_CLI=/root/.openclaw/agents/agent-gmail-test/skills/gmail/gmail_cli.py')
        // Not the MailboxKit path.
        ->not->toContain('/skills/mailboxkit');
});

test('install script omits gmail skill when the agent has no gmail connection', function () {
    $team = Team::factory()->starterPlan()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->deploying()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'harness_agent_id' => 'agent-no-gmail',
    ]);

    $script = app(AgentInstallScriptService::class)->generateScript($agent);

    expect($script)
        ->not->toContain('/skills/gmail')
        ->not->toContain('gmail_idle_watcher.py')
        ->not->toContain('GMAIL_APP_PASSWORD');
});

// --- Controller: agent-create email choice ---

test('creating an agent with Gmail stores an encrypted, space-stripped app password', function () {
    $user = User::factory()->withCompletedProfile()->create();
    $team = Team::factory()->starterPlan()->create(['user_id' => $user->id]);
    $team->members()->attach($user, ['role' => 'admin']);
    $user->switchTeam($team);
    Server::factory()->running()->create(['team_id' => $team->id]);

    Bus::fake();

    $this->actingAs($user)->post(route('agents.store'), [
        'name' => 'Client Care',
        'role' => 'custom',
        'agent_mode' => 'channel',
        'email_mode' => 'gmail',
        // Google shows App Passwords with spaces; they must be stripped.
        'gmail_address' => 'care@ruhcare.com',
        'gmail_app_password' => 'abcd efgh ijkl mnop',
    ])->assertRedirect();

    $agent = Agent::where('name', 'Client Care')->firstOrFail();
    $conn = $agent->emailConnection;

    expect($conn)->not->toBeNull()
        ->and($conn->provider)->toBe('gmail')
        ->and($conn->email_address)->toBe('care@ruhcare.com')
        ->and($conn->isGmail())->toBeTrue()
        // decrypts (cast) and spaces are gone
        ->and($conn->app_password)->toBe('abcdefghijklmnop')
        // stored ciphertext is not the plaintext
        ->and($conn->getRawOriginal('app_password'))->not->toBe('abcdefghijklmnop');
});

test('creating an agent with no email creates no email connection', function () {
    $user = User::factory()->withCompletedProfile()->create();
    $team = Team::factory()->starterPlan()->create(['user_id' => $user->id]);
    $team->members()->attach($user, ['role' => 'admin']);
    $user->switchTeam($team);
    Server::factory()->running()->create(['team_id' => $team->id]);

    Bus::fake();

    $this->actingAs($user)->post(route('agents.store'), [
        'name' => 'No Mail',
        'role' => 'custom',
        'agent_mode' => 'channel',
        'email_mode' => 'none',
    ])->assertRedirect();

    $agent = Agent::where('name', 'No Mail')->firstOrFail();
    expect($agent->emailConnection)->toBeNull();
});

test('gmail email choice requires an address and app password', function () {
    $user = User::factory()->withCompletedProfile()->create();
    $team = Team::factory()->starterPlan()->create(['user_id' => $user->id]);
    $team->members()->attach($user, ['role' => 'admin']);
    $user->switchTeam($team);
    Server::factory()->running()->create(['team_id' => $team->id]);

    $this->actingAs($user)->post(route('agents.store'), [
        'name' => 'Bad Gmail',
        'role' => 'custom',
        'agent_mode' => 'channel',
        'email_mode' => 'gmail',
    ])->assertSessionHasErrors(['gmail_address', 'gmail_app_password']);
});
