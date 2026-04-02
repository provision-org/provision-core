<?php

use App\Enums\AgentStatus;
use App\Mail\AgentDeletedMail;
use App\Models\Agent;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('agent deleted email is sent when agent is destroyed', function () {
    Bus::fake();
    Mail::fake();

    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $agent = Agent::factory()->create([
        'team_id' => $team->id,
        'status' => AgentStatus::Active,
    ]);

    $this->actingAs($user)->delete(route('agents.destroy', $agent));

    Mail::assertQueued(AgentDeletedMail::class, function ($mail) use ($user, $agent) {
        return $mail->hasTo($user->email) && $mail->agentName === $agent->name;
    });
});
