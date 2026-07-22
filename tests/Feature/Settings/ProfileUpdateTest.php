<?php

use App\Enums\TeamRole;
use App\Models\Agent;
use App\Models\AgentArtifact;
use App\Models\Team;
use App\Models\User;
use App\Services\PublishArtifactService;
use App\Services\SlackAppCleanupService;
use Provision\MailboxKit\Services\MailboxKitService;

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('profile.edit'));

    $response->assertOk();
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    $user->refresh();

    expect($user->name)->toBe('Test User');
    expect($user->email)->toBe('test@example.com');
    expect($user->email_verified_at)->toBeNull();
});

test('email verification status is unchanged when the email address is unchanged', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => $user->email,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->email_verified_at)->not->toBeNull();
});

test('user can delete their account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->delete(route('profile.destroy'), [
            'password' => 'password',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('home'));

    $this->assertGuest();
    expect($user->fresh())->toBeNull();
});

test('account deletion safely tears down every owned team without a successor', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $personalTeam = $user->personalTeam();
    $ownedTeam = Team::factory()->create(['user_id' => $user->id]);
    $ownedTeam->members()->attach($user, ['role' => TeamRole::Admin->value]);

    $agents = collect([$personalTeam, $ownedTeam])->map(function (Team $team): Agent {
        $agent = Agent::factory()->create(['team_id' => $team->id]);

        AgentArtifact::factory()->create([
            'agent_id' => $agent->id,
            'team_id' => $team->id,
        ]);

        return $agent;
    });

    if (class_exists(MailboxKitService::class)) {
        app()->instance(MailboxKitService::class, Mockery::mock(MailboxKitService::class));
    }

    $slackCleanup = Mockery::mock(SlackAppCleanupService::class);
    $slackCleanup->shouldReceive('cleanup')->twice();
    app()->instance(SlackAppCleanupService::class, $slackCleanup);

    $artifactCleanup = Mockery::mock(PublishArtifactService::class);
    $artifactCleanup->shouldReceive('teardownAgent')
        ->twice()
        ->withArgs(function (Agent $agent, bool $requireServerCleanup) use ($agents): bool {
            return $agents->contains('id', $agent->id)
                && $agent->artifacts()->exists()
                && $requireServerCleanup === false;
        });
    app()->instance(PublishArtifactService::class, $artifactCleanup);

    $response = $this
        ->actingAs($user)
        ->delete(route('profile.destroy'), [
            'password' => 'password',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('home'));

    $this->assertGuest();
    expect($user->fresh())->toBeNull()
        ->and($personalTeam->fresh())->toBeNull()
        ->and($ownedTeam->fresh())->toBeNull()
        ->and(Agent::query()->whereKey($agents->pluck('id'))->exists())->toBeFalse()
        ->and(AgentArtifact::query()->whereIn('agent_id', $agents->pluck('id'))->exists())->toBeFalse();
});

test('account deletion fails closed when owned team artifact cleanup fails', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->personalTeam();
    $agent = Agent::factory()->create(['team_id' => $team->id]);
    $artifact = AgentArtifact::factory()->create([
        'agent_id' => $agent->id,
        'team_id' => $team->id,
    ]);

    if (class_exists(MailboxKitService::class)) {
        app()->instance(MailboxKitService::class, Mockery::mock(MailboxKitService::class));
    }

    $slackCleanup = Mockery::mock(SlackAppCleanupService::class);
    $slackCleanup->shouldReceive('cleanup')->once();
    app()->instance(SlackAppCleanupService::class, $slackCleanup);

    $artifactCleanup = Mockery::mock(PublishArtifactService::class);
    $artifactCleanup->shouldReceive('teardownAgent')
        ->once()
        ->andThrow(new RuntimeException('DNS cleanup failed'));
    app()->instance(PublishArtifactService::class, $artifactCleanup);

    $this->withoutExceptionHandling();
    $this->actingAs($user);

    expect(fn () => $this->delete(route('profile.destroy'), [
        'password' => 'password',
    ]))->toThrow(RuntimeException::class, 'Artifact DNS cleanup failed; team retained for retry.');

    $this->assertAuthenticatedAs($user);
    expect($user->fresh())->not->toBeNull()
        ->and($team->fresh())->not->toBeNull()
        ->and($agent->fresh())->not->toBeNull()
        ->and($artifact->fresh())->not->toBeNull();
});

test('correct password must be provided to delete account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from(route('profile.edit'))
        ->delete(route('profile.destroy'), [
            'password' => 'wrong-password',
        ]);

    $response
        ->assertSessionHasErrors('password')
        ->assertRedirect(route('profile.edit'));

    expect($user->fresh())->not->toBeNull();
});
