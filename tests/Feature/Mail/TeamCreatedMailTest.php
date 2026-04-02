<?php

use App\Mail\TeamCreatedMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

test('team created email is sent when a team is created', function () {
    Bus::fake();
    Mail::fake();

    $user = User::factory()->withPersonalTeam()->create();

    $this->actingAs($user)->post(route('teams.store'), [
        'name' => 'New Team',
        'harness_type' => 'openclaw',
    ]);

    Mail::assertQueued(TeamCreatedMail::class, function ($mail) use ($user) {
        return $mail->hasTo($user->email);
    });
});
