<?php

use App\Enums\TeamRole;
use App\Jobs\UpdateEnvOnServerJob;
use App\Models\Server;
use App\Models\Team;
use App\Models\TeamEnvVar;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

test('env var belongs to team', function () {
    $envVar = TeamEnvVar::factory()->create();

    expect($envVar->team)->toBeInstanceOf(Team::class);
});

test('value is encrypted in database', function () {
    $envVar = TeamEnvVar::factory()->create(['value' => 'my-secret-value']);

    $raw = DB::table('team_env_vars')->where('id', $envVar->id)->value('value');

    expect($raw)->not->toBe('my-secret-value')
        ->and($envVar->value)->toBe('my-secret-value');
});

test('valuePreview masks secrets', function () {
    $envVar = TeamEnvVar::factory()->secret()->create(['value' => 'super-secret']);

    expect($envVar->valuePreview())->toBe('••••••••');
});

test('valuePreview shows value for non-secrets', function () {
    $envVar = TeamEnvVar::factory()->create(['value' => 'visible-value', 'is_secret' => false]);

    expect($envVar->valuePreview())->toBe('visible-value');
});

test('factory creates valid var', function () {
    $envVar = TeamEnvVar::factory()->create();

    expect($envVar->id)->toBeGreaterThan(0)
        ->and($envVar->team_id)->toBeGreaterThan(0)
        ->and($envVar->key)->toBeString()
        ->and($envVar->value)->toBeString()
        ->and($envVar->is_secret)->toBeFalse();
});

test('unique constraint on team_id and key', function () {
    $team = Team::factory()->create();

    TeamEnvVar::factory()->create([
        'team_id' => $team->id,
        'key' => 'MY_VAR',
    ]);

    expect(fn () => TeamEnvVar::factory()->create([
        'team_id' => $team->id,
        'key' => 'MY_VAR',
    ]))->toThrow(UniqueConstraintViolationException::class);
});

test('admin can create an env var', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;

    $response = $this->actingAs($user)->post(route('api-keys.env-vars.store'), [
        'key' => 'MY_VARIABLE',
        'value' => 'some-value',
        'is_secret' => false,
    ]);

    $response->assertRedirect();
    expect($team->envVars)->toHaveCount(1)
        ->and($team->envVars->first()->key)->toBe('MY_VARIABLE');
});

test('admin can update an env var', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $envVar = TeamEnvVar::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)->patch(route('api-keys.env-vars.update', $envVar), [
        'value' => 'updated-value',
    ]);

    $response->assertRedirect();
    expect($envVar->fresh()->value)->toBe('updated-value');
});

test('admin can delete an env var', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $envVar = TeamEnvVar::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)->delete(route('api-keys.env-vars.destroy', $envVar));

    $response->assertRedirect();
    expect(TeamEnvVar::find($envVar->id))->toBeNull();
});

test('store dispatches UpdateEnvOnServerJob when team has server', function () {
    Bus::fake();

    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    Server::factory()->running()->create(['team_id' => $team->id]);

    $this->actingAs($user)->post(route('api-keys.env-vars.store'), [
        'key' => 'MY_VAR',
        'value' => 'some-value',
    ]);

    Bus::assertDispatched(UpdateEnvOnServerJob::class);
});

test('store does not dispatch job when team has no server', function () {
    Bus::fake();

    $user = User::factory()->withPersonalTeam()->create();

    $this->actingAs($user)->post(route('api-keys.env-vars.store'), [
        'key' => 'MY_VAR',
        'value' => 'some-value',
    ]);

    Bus::assertNotDispatched(UpdateEnvOnServerJob::class);
});

test('non-admin cannot manage env vars', function () {
    $owner = User::factory()->withPersonalTeam()->create();
    $team = $owner->currentTeam;
    $member = User::factory()->withCompletedProfile()->create();
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $member->forceFill(['current_team_id' => $team->id])->save();

    $response = $this->actingAs($member)->post(route('api-keys.env-vars.store'), [
        'key' => 'MY_VAR',
        'value' => 'some-value',
    ]);

    $response->assertForbidden();
});

test('key must be uppercase with underscores', function () {
    $user = User::factory()->withPersonalTeam()->create();

    $response = $this->actingAs($user)->post(route('api-keys.env-vars.store'), [
        'key' => 'invalid-key',
        'value' => 'some-value',
    ]);

    $response->assertSessionHasErrors('key');
});

test('key cannot start with a number', function () {
    $user = User::factory()->withPersonalTeam()->create();

    $response = $this->actingAs($user)->post(route('api-keys.env-vars.store'), [
        'key' => '123_KEY',
        'value' => 'some-value',
    ]);

    $response->assertSessionHasErrors('key');
});

test('value is required when storing', function () {
    $user = User::factory()->withPersonalTeam()->create();

    $response = $this->actingAs($user)->post(route('api-keys.env-vars.store'), [
        'key' => 'MY_VAR',
        'value' => '',
    ]);

    $response->assertSessionHasErrors('value');
});
