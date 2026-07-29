<?php

use App\Models\AgentApiToken;
use App\Models\ManagedApiKey;
use App\Models\SlackConfigurationToken;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Every model with an `encrypted` cast must also hide that field, or the
// decrypted secret serializes in plaintext into any payload carrying the model.

test('team api keys never serialize their decrypted key', function () {
    $team = Team::factory()->create();
    $key = $team->apiKeys()->create([
        'provider' => 'aws',
        'api_key' => 'SECRET_AWS_KEY_123',
        'is_active' => true,
    ]);

    expect($key->toArray())->not->toHaveKey('api_key')
        ->and($key->toJson())->not->toContain('SECRET_AWS_KEY_123')
        // Masking still works — it reads the decrypted value internally.
        ->and($key->maskedKey())->toContain('SECRET')
        ->and($key->api_key)->toBe('SECRET_AWS_KEY_123');
});

test('team env vars never serialize their decrypted value', function () {
    $team = Team::factory()->create();
    $var = $team->envVars()->create([
        'key' => 'STRIPE_SK',
        'value' => 'sk_live_SECRET_456',
        'is_secret' => true,
    ]);

    expect($var->toArray())->not->toHaveKey('value')
        ->and($var->toJson())->not->toContain('sk_live_SECRET_456')
        // Backend access (provisioning reads the real value) still works.
        ->and($var->value)->toBe('sk_live_SECRET_456');
});

test('a serialized team never leaks its api keys or env var secrets', function () {
    // Mirrors the settings controllers: they access these relations (loading
    // them onto $team) and then pass 'team' => $team to Inertia.
    $team = Team::factory()->create();
    $team->apiKeys()->create(['provider' => 'aws', 'api_key' => 'SECRET_AWS_KEY_123', 'is_active' => true]);
    $team->envVars()->create(['key' => 'STRIPE_SK', 'value' => 'sk_live_SECRET_456', 'is_secret' => true]);

    $team->load('apiKeys', 'envVars');
    $json = $team->toJson();

    expect($json)
        ->not->toContain('SECRET_AWS_KEY_123')
        ->not->toContain('sk_live_SECRET_456');
});

test('managed api key, agent token and slack tokens are hidden', function () {
    $managed = new ManagedApiKey(['api_key' => 'or_secret']);
    $agentToken = new AgentApiToken(['token_encrypted' => 'tok_secret']);
    $slack = new SlackConfigurationToken([
        'access_token' => 'xoxb_secret',
        'refresh_token' => 'xoxe_secret',
    ]);

    expect($managed->toArray())->not->toHaveKey('api_key')
        ->and($agentToken->toArray())->not->toHaveKey('token_encrypted')
        ->and($slack->toArray())->not->toHaveKey('access_token')
        ->and($slack->toArray())->not->toHaveKey('refresh_token');
});
