<?php

use App\Jobs\ProvisionAwsServerJob;
use App\Jobs\ProvisionDigitalOceanServerJob;
use App\Jobs\ProvisionHetznerServerJob;
use App\Models\User;
use App\Services\AwsService;
use App\Services\CloudServiceFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

/**
 * Swap the CloudServiceFactory so BYO-AWS credential verification never
 * hits real AWS: makeAwsForCredentials returns an AwsService double whose
 * verifyCredentials either succeeds or throws like a real auth failure.
 */
function mockAwsCredentialVerification(bool $succeeds = true): void
{
    $aws = Mockery::mock(AwsService::class);

    if ($succeeds) {
        $aws->shouldReceive('verifyCredentials')->andReturn([
            'account_id' => '123456789012',
            'arn' => 'arn:aws:iam::123456789012:user/provision',
        ]);
    } else {
        $aws->shouldReceive('verifyCredentials')->andThrow(
            new RuntimeException('AWS GetCallerIdentity failed: The security token included in the request is invalid.'),
        );
    }

    test()->mock(CloudServiceFactory::class, function ($mock) use ($aws): void {
        $mock->shouldReceive('makeAwsForCredentials')->andReturn($aws);
    });
}

test('creating a team does not dispatch ProvisionHetznerServerJob', function () {
    Bus::fake();
    $user = User::factory()->withCompletedProfile()->create();

    $this->actingAs($user)->post(route('teams.store'), [
        'name' => 'My New Team',
        'harness_type' => 'hermes',
    ]);

    Bus::assertNotDispatched(ProvisionHetznerServerJob::class);
    Bus::assertNotDispatched(ProvisionDigitalOceanServerJob::class);
});

test('creating a team creates a server record', function () {
    Bus::fake();
    $user = User::factory()->withCompletedProfile()->create();

    $this->actingAs($user)->post(route('teams.store'), [
        'name' => 'My New Team',
        'harness_type' => 'hermes',
    ]);

    $team = $user->fresh()->currentTeam;

    expect($team)->not->toBeNull();
    expect($team->server)->not->toBeNull();
});

test('creating a team switches the user to the new team', function () {
    Bus::fake();
    $user = User::factory()->withCompletedProfile()->create();

    $this->actingAs($user)->post(route('teams.store'), [
        'name' => 'My New Team',
        'harness_type' => 'hermes',
    ]);

    $user->refresh();

    expect($user->current_team_id)->not->toBeNull()
        ->and($user->currentTeam->name)->toBe('My New Team');
});

test('server.region matches the chosen cloud provider, not the migration default (issue #30)', function () {
    Bus::fake();
    config()->set('cloud.provider_selection_enabled', true);
    $user = User::factory()->withCompletedProfile()->create();

    $this->actingAs($user)->post(route('teams.store'), [
        'name' => 'DO Team',
        'harness_type' => 'openclaw',
        'cloud_provider' => 'digitalocean',
    ]);

    $team = $user->fresh()->currentTeam;
    expect($team->server->cloud_provider->value)->toBe('digitalocean')
        ->and($team->server->region)->toBe('nyc1');
});

test('a user without byo_cloud_enabled cannot create an aws team', function () {
    Bus::fake();
    config()->set('cloud.provider_selection_enabled', true);
    $user = User::factory()->withCompletedProfile()->create();

    $response = $this->actingAs($user)->post(route('teams.store'), [
        'name' => 'AWS Team',
        'harness_type' => 'openclaw',
        'cloud_provider' => 'aws',
        'aws_key_id' => 'AKIAEXAMPLE000000000',
        'aws_secret' => 'super-secret',
        'aws_region' => 'us-east-1',
    ]);

    $response->assertSessionHasErrors('cloud_provider');
    expect($user->fresh()->currentTeam)->toBeNull();
    Bus::assertNotDispatched(ProvisionAwsServerJob::class);
});

test('an aws team requires credentials', function () {
    Bus::fake();
    config()->set('cloud.provider_selection_enabled', true);
    $user = User::factory()->withCompletedProfile()->byoCloud()->create();

    $response = $this->actingAs($user)->post(route('teams.store'), [
        'name' => 'AWS Team',
        'harness_type' => 'openclaw',
        'cloud_provider' => 'aws',
    ]);

    $response->assertSessionHasErrors(['aws_key_id', 'aws_secret']);
    Bus::assertNotDispatched(ProvisionAwsServerJob::class);
});

test('a byo_cloud_enabled user can create an aws team with stored credentials', function () {
    Bus::fake();
    config()->set('cloud.provider_selection_enabled', true);
    mockAwsCredentialVerification();
    $user = User::factory()->withCompletedProfile()->byoCloud()->create();

    $this->actingAs($user)->post(route('teams.store'), [
        'name' => 'AWS Team',
        'harness_type' => 'openclaw',
        'cloud_provider' => 'aws',
        'aws_key_id' => 'AKIAEXAMPLE000000000',
        'aws_secret' => 'super-secret',
        'aws_region' => 'eu-central-1',
        'aws_instance_profile' => 'provision-bedrock',
    ]);

    $team = $user->fresh()->currentTeam;

    expect($team)->not->toBeNull()
        ->and($team->server->cloud_provider->value)->toBe('aws')
        ->and($team->server->region)->toBe('us-east-1');

    $key = $team->cloudApiKeys()->where('provider', 'aws')->first();
    expect($key)->not->toBeNull()
        ->and($key->is_active)->toBeTrue();

    $credentials = json_decode($key->api_key, true);
    expect($credentials['key_id'])->toBe('AKIAEXAMPLE000000000')
        ->and($credentials['secret'])->toBe('super-secret')
        ->and($credentials['region'])->toBe('eu-central-1')
        ->and($credentials['instance_profile'])->toBe('provision-bedrock');

    Bus::assertDispatched(ProvisionAwsServerJob::class);
});

test('an aws team requires an instance profile for the Bedrock role', function () {
    Bus::fake();
    config()->set('cloud.provider_selection_enabled', true);
    $user = User::factory()->withCompletedProfile()->byoCloud()->create();

    $response = $this->actingAs($user)->post(route('teams.store'), [
        'name' => 'AWS Team',
        'harness_type' => 'openclaw',
        'cloud_provider' => 'aws',
        'aws_key_id' => 'AKIAEXAMPLE000000000',
        'aws_secret' => 'super-secret',
        'aws_region' => 'us-east-1',
    ]);

    $response->assertSessionHasErrors('aws_instance_profile');
    Bus::assertNotDispatched(ProvisionAwsServerJob::class);
});

test('server.region uses provider-specific code for Hetzner', function () {
    Bus::fake();
    config()->set('cloud.provider_selection_enabled', true);
    $user = User::factory()->withCompletedProfile()->create();

    $this->actingAs($user)->post(route('teams.store'), [
        'name' => 'Hetzner Team',
        'harness_type' => 'openclaw',
        'cloud_provider' => 'hetzner',
    ]);

    $team = $user->fresh()->currentTeam;
    expect($team->server->cloud_provider->value)->toBe('hetzner')
        ->and($team->server->region)->toBe('ash');
});

test('a byo_cloud_enabled user gets the managed default plus their own AWS in the provider step', function () {
    config()->set('cloud.provider_selection_enabled', false);
    config()->set('cloud.default_provider', 'digitalocean');
    $user = User::factory()->withCompletedProfile()->byoCloud()->create();

    $response = $this->actingAs($user)->get(route('teams.create'));

    $response->assertInertia(fn ($page) => $page
        ->component('settings/teams/create')
        ->where('cloudProviderSelectionEnabled', true)
        ->where('byoCloudEnabled', true)
        ->has('availableProviders', 2)
        ->where('availableProviders.0.value', 'digitalocean')
        ->where('availableProviders.1.value', 'aws'));
});

test('a byo_cloud_enabled user can create a team on the managed cloud', function () {
    Bus::fake();
    config()->set('cloud.provider_selection_enabled', false);
    $user = User::factory()->withCompletedProfile()->byoCloud()->create();

    $this->actingAs($user)->post(route('teams.store'), [
        'name' => 'Managed Team',
        'harness_type' => 'openclaw',
        'cloud_provider' => 'digitalocean',
    ]);

    $team = $user->fresh()->currentTeam;

    expect($team)->not->toBeNull()
        ->and($team->server->cloud_provider->value)->toBe('digitalocean');
    Bus::assertNotDispatched(ProvisionAwsServerJob::class);
});

test('a byo_cloud_enabled user can create a team without any server details on the managed default', function () {
    Bus::fake();
    config()->set('cloud.provider_selection_enabled', false);
    $user = User::factory()->withCompletedProfile()->byoCloud()->create();

    $this->actingAs($user)->post(route('teams.store'), [
        'name' => 'No Creds Team',
        'harness_type' => 'openclaw',
    ]);

    expect($user->fresh()->currentTeam)->not->toBeNull();
    Bus::assertNotDispatched(ProvisionAwsServerJob::class);
});

test('an aws team is not created when credential verification fails', function () {
    Bus::fake();
    config()->set('cloud.provider_selection_enabled', true);
    mockAwsCredentialVerification(succeeds: false);
    $user = User::factory()->withCompletedProfile()->byoCloud()->create();

    $response = $this->actingAs($user)->post(route('teams.store'), [
        'name' => 'Bad Creds Team',
        'harness_type' => 'openclaw',
        'cloud_provider' => 'aws',
        'aws_key_id' => 'AKIABOGUS00000000000',
        'aws_secret' => 'wrong-secret',
        'aws_region' => 'us-east-1',
        'aws_instance_profile' => 'provision-bedrock',
    ]);

    $response->assertSessionHasErrors('aws_key_id');
    expect($user->fresh()->currentTeam)->toBeNull()
        ->and($user->ownedTeams()->count())->toBe(0);
    Bus::assertNotDispatched(ProvisionAwsServerJob::class);
});

test('verify-aws is forbidden for users without byo_cloud_enabled', function () {
    $user = User::factory()->withCompletedProfile()->create();

    $response = $this->actingAs($user)->postJson(route('teams.verify-aws'), [
        'aws_key_id' => 'AKIAEXAMPLE000000000',
        'aws_secret' => 'super-secret',
        'aws_region' => 'us-east-1',
    ]);

    $response->assertForbidden();
});

test('verify-aws returns the account id for valid credentials', function () {
    mockAwsCredentialVerification();
    $user = User::factory()->withCompletedProfile()->byoCloud()->create();

    $response = $this->actingAs($user)->postJson(route('teams.verify-aws'), [
        'aws_key_id' => 'AKIAEXAMPLE000000000',
        'aws_secret' => 'super-secret',
        'aws_region' => 'us-east-1',
    ]);

    $response->assertOk()->assertJson([
        'verified' => true,
        'account_id' => '123456789012',
    ]);
});

test('verify-aws returns 422 with a readable message when AWS rejects the credentials', function () {
    mockAwsCredentialVerification(succeeds: false);
    $user = User::factory()->withCompletedProfile()->byoCloud()->create();

    $response = $this->actingAs($user)->postJson(route('teams.verify-aws'), [
        'aws_key_id' => 'AKIABOGUS00000000000',
        'aws_secret' => 'wrong-secret',
        'aws_region' => 'us-east-1',
    ]);

    $response->assertStatus(422)->assertJson([
        'verified' => false,
        'message' => 'AWS GetCallerIdentity failed: The security token included in the request is invalid.',
    ]);
});

test('a user without byo_cloud_enabled sees no provider step when global selection is disabled', function () {
    config()->set('cloud.provider_selection_enabled', false);
    $user = User::factory()->withCompletedProfile()->create();

    $response = $this->actingAs($user)->get(route('teams.create'));

    $response->assertInertia(fn ($page) => $page
        ->component('settings/teams/create')
        ->where('cloudProviderSelectionEnabled', false)
        ->where('byoCloudEnabled', false));
});
