<?php

use App\Enums\LlmProvider;
use App\Enums\TeamRole;
use App\Jobs\UpdateEnvOnServerJob;
use App\Models\Server;
use App\Models\Team;
use App\Models\TeamApiKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

test('api key belongs to team', function () {
    $apiKey = TeamApiKey::factory()->create();

    expect($apiKey->team)->toBeInstanceOf(Team::class);
});

test('api_key is encrypted in database', function () {
    $apiKey = TeamApiKey::factory()->create(['api_key' => 'sk-test-secret-key-12345']);

    $raw = DB::table('team_api_keys')->where('id', $apiKey->id)->value('api_key');

    expect($raw)->not->toBe('sk-test-secret-key-12345')
        ->and($apiKey->api_key)->toBe('sk-test-secret-key-12345');
});

test('maskedKey accessor returns partial key', function () {
    $apiKey = TeamApiKey::factory()->create(['api_key' => 'sk-test-1234567890abcdef']);

    expect($apiKey->maskedKey())->toBe('sk-test-...cdef');
});

test('factory creates valid key', function () {
    $apiKey = TeamApiKey::factory()->create();

    expect($apiKey->id)->toBeGreaterThan(0)
        ->and($apiKey->team_id)->toBeGreaterThan(0)
        ->and($apiKey->provider)->toBeInstanceOf(LlmProvider::class)
        ->and($apiKey->api_key)->toBeString()
        ->and($apiKey->is_active)->toBeTrue();
});

test('unique constraint on team_id and provider', function () {
    $team = Team::factory()->create();

    TeamApiKey::factory()->create([
        'team_id' => $team->id,
        'provider' => LlmProvider::Anthropic,
    ]);

    expect(fn () => TeamApiKey::factory()->create([
        'team_id' => $team->id,
        'provider' => LlmProvider::Anthropic,
    ]))->toThrow(\Illuminate\Database\UniqueConstraintViolationException::class);
});

test('admin can create an api key', function () {
    $user = User::factory()->withPersonalTeam()->create();

    $response = $this->actingAs($user)->post(route('api-keys.store'), [
        'provider' => 'anthropic',
        'api_key' => 'sk-ant-test-1234567890',
    ]);

    $response->assertRedirect();
    $team = $user->currentTeam;
    expect($team->apiKeys)->toHaveCount(1)
        ->and($team->apiKeys->first()->provider)->toBe(LlmProvider::Anthropic);
});

test('store upserts existing provider key', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;

    TeamApiKey::factory()->create([
        'team_id' => $team->id,
        'provider' => LlmProvider::Anthropic,
        'api_key' => 'sk-old-key-1234567890',
    ]);

    $this->actingAs($user)->post(route('api-keys.store'), [
        'provider' => 'anthropic',
        'api_key' => 'sk-new-key-1234567890',
    ]);

    expect($team->apiKeys()->count())->toBe(1)
        ->and($team->apiKeys->first()->api_key)->toBe('sk-new-key-1234567890');
});

test('admin can update an api key', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $apiKey = TeamApiKey::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)->patch(route('api-keys.update', $apiKey), [
        'is_active' => false,
    ]);

    $response->assertRedirect();
    expect($apiKey->fresh()->is_active)->toBeFalse();
});

test('admin can delete an api key', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $apiKey = TeamApiKey::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)->delete(route('api-keys.destroy', $apiKey));

    $response->assertRedirect();
    expect(TeamApiKey::find($apiKey->id))->toBeNull();
});

test('store dispatches UpdateEnvOnServerJob when team has server', function () {
    Bus::fake();

    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    Server::factory()->running()->create(['team_id' => $team->id]);

    $this->actingAs($user)->post(route('api-keys.store'), [
        'provider' => 'anthropic',
        'api_key' => 'sk-ant-test-1234567890',
    ]);

    Bus::assertDispatched(UpdateEnvOnServerJob::class);
});

test('store does not dispatch job when team has no server', function () {
    Bus::fake();

    $user = User::factory()->withPersonalTeam()->create();

    $this->actingAs($user)->post(route('api-keys.store'), [
        'provider' => 'anthropic',
        'api_key' => 'sk-ant-test-1234567890',
    ]);

    Bus::assertNotDispatched(UpdateEnvOnServerJob::class);
});

test('non-admin cannot manage api keys', function () {
    $owner = User::factory()->withPersonalTeam()->create();
    $team = $owner->currentTeam;
    $member = User::factory()->withCompletedProfile()->create();
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $member->forceFill(['current_team_id' => $team->id])->save();

    $response = $this->actingAs($member)->post(route('api-keys.store'), [
        'provider' => 'anthropic',
        'api_key' => 'sk-ant-test-1234567890',
    ]);

    $response->assertForbidden();
});

test('provider must be a valid enum value', function () {
    $user = User::factory()->withPersonalTeam()->create();

    $response = $this->actingAs($user)->post(route('api-keys.store'), [
        'provider' => 'invalid_provider',
        'api_key' => 'sk-ant-test-1234567890',
    ]);

    $response->assertSessionHasErrors('provider');
});

test('api_key is required when storing', function () {
    $user = User::factory()->withPersonalTeam()->create();

    $response = $this->actingAs($user)->post(route('api-keys.store'), [
        'provider' => 'anthropic',
        'api_key' => '',
    ]);

    $response->assertSessionHasErrors('api_key');
});

test('api_key must be at least 10 characters', function () {
    $user = User::factory()->withPersonalTeam()->create();

    $response = $this->actingAs($user)->post(route('api-keys.store'), [
        'provider' => 'anthropic',
        'api_key' => 'short',
    ]);

    $response->assertSessionHasErrors('api_key');
});
