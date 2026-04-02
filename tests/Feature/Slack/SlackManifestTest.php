<?php

use App\Models\Agent;
use App\Services\SlackManifestService;

test('manifest contains agent name', function () {
    $agent = Agent::factory()->create(['name' => 'Sales Bot']);
    $service = new SlackManifestService;
    $manifest = $service->generateManifest($agent);

    expect($manifest['display_information']['name'])->toBe('Sales Bot')
        ->and($manifest['features']['bot_user']['display_name'])->toBe('Sales Bot');
});

test('manifest contains required scopes', function () {
    $agent = Agent::factory()->create();
    $service = new SlackManifestService;
    $manifest = $service->generateManifest($agent);

    expect($manifest['oauth_config']['scopes']['bot'])
        ->toContain('channels:history')
        ->toContain('channels:read')
        ->toContain('chat:write')
        ->toContain('files:read')
        ->toContain('files:write')
        ->toContain('users:read')
        ->toContain('app_mentions:read');
});

test('manifest has socket mode enabled', function () {
    $agent = Agent::factory()->create();
    $service = new SlackManifestService;
    $manifest = $service->generateManifest($agent);

    expect($manifest['settings']['socket_mode_enabled'])->toBeTrue();
});

test('manifest contains agent description', function () {
    $agent = Agent::factory()->create(['name' => 'Support Bot']);
    $service = new SlackManifestService;
    $manifest = $service->generateManifest($agent);

    expect($manifest['display_information']['description'])->toBe('Provision AI Agent: Support Bot');
});

test('manifest includes bot user with always online', function () {
    $agent = Agent::factory()->create();
    $service = new SlackManifestService;
    $manifest = $service->generateManifest($agent);

    expect($manifest['features']['bot_user']['always_online'])->toBeTrue();
});

test('manifest includes event subscriptions', function () {
    $agent = Agent::factory()->create();
    $service = new SlackManifestService;
    $manifest = $service->generateManifest($agent);

    expect($manifest['settings']['event_subscriptions']['bot_events'])
        ->toContain('app_mention')
        ->toContain('message.channels');
});
