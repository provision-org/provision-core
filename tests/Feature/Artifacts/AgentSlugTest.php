<?php

use App\Models\Agent;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('agents get a globally unique slug on creation', function () {
    $a = Agent::factory()->create(['name' => 'Luna']);
    $b = Agent::factory()->create(['name' => 'Luna']); // different team

    expect($a->slug)->toBe('luna')
        ->and($b->slug)->toBe('luna-2');
});

test('an explicit slug is respected', function () {
    $agent = Agent::factory()->create(['name' => 'Luna', 'slug' => 'custom-slug']);

    expect($agent->slug)->toBe('custom-slug');
});

test('artifactSubdomain uses the configured artifact domain', function () {
    config(['cloudflare.artifact_domain' => 'provisionagents.com']);
    $agent = Agent::factory()->create(['name' => 'Luna']);

    expect($agent->artifactSubdomain())->toBe('luna.provisionagents.com');
});

test('artifactSubdomain is null when no artifact domain is configured', function () {
    config(['cloudflare.artifact_domain' => null]);
    $agent = Agent::factory()->create(['name' => 'Luna']);

    expect($agent->artifactSubdomain())->toBeNull();
});
