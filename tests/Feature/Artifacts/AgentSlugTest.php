<?php

use App\Models\Agent;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('agents get globally unique hostname slugs on creation', function () {
    $a = Agent::factory()->create(['name' => 'Luna']);
    $b = Agent::factory()->create(['name' => 'Luna']); // different team

    expect($a->slug)->toBe("luna-{$a->id}")
        ->and($b->slug)->toBe("luna-{$b->id}")
        ->and($b->slug)->not->toBe($a->slug);
});

test('an explicit slug is used as the unique hostname base', function () {
    $agent = Agent::factory()->create(['name' => 'Luna', 'slug' => 'custom-slug']);

    expect($agent->slug)->toBe("custom-slug-{$agent->id}");
});

test('a deleted agent hostname slug is not reused by another tenant', function () {
    $deleted = Agent::factory()->create(['name' => 'Luna']);
    $deletedSlug = $deleted->slug;

    $deleted->delete();

    $replacement = Agent::factory()->create(['name' => 'Luna']);

    expect($replacement->slug)->not->toBe($deletedSlug);
});

test('hostname slugs stay within the DNS label length limit', function () {
    $agent = Agent::factory()->create([
        'name' => str_repeat('Very Long Agent Name ', 10),
    ]);

    expect(strlen($agent->slug))->toBeLessThanOrEqual(63)
        ->and($agent->slug)->toMatch('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/');
});

test('artifactSubdomain uses the configured artifact domain', function () {
    config(['cloudflare.artifact_domain' => 'provisionagents.com']);
    $agent = Agent::factory()->create(['name' => 'Luna']);

    expect($agent->artifactSubdomain())->toBe("{$agent->slug}.provisionagents.com");
});

test('artifactSubdomain is null when no artifact domain is configured', function () {
    config(['cloudflare.artifact_domain' => null]);
    $agent = Agent::factory()->create(['name' => 'Luna']);

    expect($agent->artifactSubdomain())->toBeNull();
});

test('artifactSubdomain keeps the persisted hostname after configuration changes', function () {
    config(['cloudflare.artifact_domain' => 'provisionagents.com']);
    $agent = Agent::factory()->create(['name' => 'Luna']);
    $publishedHostname = "{$agent->slug}.provisionagents.com";
    $agent->forceFill(['artifact_dns_record_name' => $publishedHostname])->save();

    config(['cloudflare.artifact_domain' => 'new-provisionagents.com']);

    expect($agent->artifactSubdomain())->toBe($publishedHostname)
        ->and($agent->fresh()->artifactSubdomain())->toBe($publishedHostname);
});
