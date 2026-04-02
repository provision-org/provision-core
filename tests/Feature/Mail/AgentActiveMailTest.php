<?php

use App\Enums\AgentStatus;
use App\Mail\AgentActiveMail;
use App\Models\Agent;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('agent active email is sent when agent is activated', function () {
    Mail::fake();

    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $server = Server::factory()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'status' => AgentStatus::Deploying,
    ]);

    // Simulate what activateAgent() does
    $agent->update(['status' => AgentStatus::Active]);
    $agent->server->events()->create([
        'event' => 'agent_install_complete',
        'payload' => ['agent_id' => $agent->id],
    ]);

    \Illuminate\Support\Facades\Mail::to($team->owner->email)->send(new AgentActiveMail($agent));

    Mail::assertQueued(AgentActiveMail::class, function ($mail) use ($user) {
        return $mail->hasTo($user->email);
    });
});
