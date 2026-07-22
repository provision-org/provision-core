<?php

use App\Models\Agent;
use App\Models\Server;
use App\Services\CloudflareDnsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'cloudflare.api_token' => 'test-token',
        'cloudflare.zone_id' => 'zone-123',
        'cloudflare.artifact_domain' => 'provisionagents.com',
    ]);
});

function artifactAgent(string $ip = '203.0.113.7'): Agent
{
    $server = Server::factory()->running()->create(['ipv4_address' => $ip]);

    return Agent::factory()->create(['name' => 'Luna', 'server_id' => $server->id]);
}

test('ensureAgentRecord creates an A record pointing at the server ip', function () {
    Http::fake(function ($request) {
        return match ($request->method()) {
            'GET' => Http::response(['result' => []]),
            'POST' => Http::response(['result' => ['id' => 'rec_new']]),
            default => Http::response([]),
        };
    });

    $agent = artifactAgent('203.0.113.7');
    $id = app(CloudflareDnsService::class)->ensureAgentRecord($agent);
    $recordName = $agent->artifactSubdomain();

    expect($id)->toBe('rec_new');
    expect($agent->fresh())
        ->artifact_dns_record_id->toBe('rec_new')
        ->artifact_dns_record_name->toBe($recordName)
        ->artifact_dns_zone_id->toBe('zone-123');

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && $request['type'] === 'A'
        && $request['name'] === $recordName
        && $request['content'] === '203.0.113.7'
        && $request['proxied'] === false);
});

test('ensureAgentRecord is idempotent when the record already matches', function () {
    Http::fake(fn ($request) => $request->method() === 'GET'
        ? Http::response(['result' => [[
            'id' => 'rec_existing',
            'content' => '203.0.113.7',
            'proxied' => false,
        ]]])
        : Http::response([]));

    $agent = artifactAgent('203.0.113.7');
    $id = app(CloudflareDnsService::class)->ensureAgentRecord($agent);

    expect($id)->toBe('rec_existing');
    Http::assertNotSent(fn ($request) => in_array($request->method(), ['POST', 'PATCH', 'DELETE'], true));
});

test('ensureAgentRecord repoints an existing record when the ip changed', function () {
    Http::fake(function ($request) {
        return match ($request->method()) {
            'GET' => Http::response(['result' => [[
                'id' => 'rec_existing',
                'content' => '198.51.100.1',
                'proxied' => true,
            ]]]),
            'PATCH' => Http::response(['result' => ['id' => 'rec_existing']]),
            default => Http::response([]),
        };
    });

    $agent = artifactAgent('203.0.113.7');
    app(CloudflareDnsService::class)->ensureAgentRecord($agent);

    Http::assertSent(fn ($request) => $request->method() === 'PATCH'
        && $request['content'] === '203.0.113.7'
        && $request['proxied'] === false);
});

test('ensureAgentRecord removes duplicate records while retaining the primary record', function () {
    Http::fake(function ($request) {
        return match ($request->method()) {
            'GET' => Http::response(['result' => [
                ['id' => 'rec_primary', 'content' => '203.0.113.7', 'proxied' => false],
                ['id' => 'rec_duplicate_one', 'content' => '203.0.113.7', 'proxied' => false],
                ['id' => 'rec_duplicate_two', 'content' => '198.51.100.1', 'proxied' => true],
            ]]),
            'DELETE' => Http::response(['result' => ['id' => basename($request->url())]]),
            default => Http::response([]),
        };
    });

    $agent = artifactAgent('203.0.113.7');
    $id = app(CloudflareDnsService::class)->ensureAgentRecord($agent);

    expect($id)->toBe('rec_primary');
    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && str_ends_with($request->url(), '/rec_duplicate_one'));
    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && str_ends_with($request->url(), '/rec_duplicate_two'));
    Http::assertNotSent(fn ($request) => in_array($request->method(), ['POST', 'PATCH'], true));
});

test('removeAgentRecord deletes the record when present', function () {
    Http::fake(function ($request) {
        return match ($request->method()) {
            'GET' => Http::response(['result' => [['id' => 'rec_existing', 'content' => '203.0.113.7']]]),
            'DELETE' => Http::response(['result' => ['id' => 'rec_existing']]),
            default => Http::response([]),
        };
    });

    $agent = artifactAgent();
    app(CloudflareDnsService::class)->removeAgentRecord($agent);

    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && str_contains($request->url(), 'rec_existing'));
    expect($agent->fresh()->artifact_dns_record_name)->toBeNull();
});

test('removeAgentRecord fails closed when the managed hostname configuration changed', function () {
    Http::fake();
    $agent = artifactAgent();
    $agent->forceFill([
        'artifact_dns_record_id' => 'rec_existing',
        'artifact_dns_record_name' => $agent->artifactSubdomain(),
        'artifact_dns_zone_id' => 'zone-123',
    ])->save();
    config(['cloudflare.artifact_domain' => 'new-provisionagents.com']);

    expect(fn () => app(CloudflareDnsService::class)->removeAgentRecord($agent))
        ->toThrow(RuntimeException::class, 'configuration changed');

    Http::assertNothingSent();
});

test('ensureAgentRecord fails closed before overwriting persisted DNS identity', function () {
    Http::fake();
    $agent = artifactAgent();
    $publishedHostname = $agent->artifactSubdomain();
    $agent->forceFill([
        'artifact_dns_record_id' => 'rec_existing',
        'artifact_dns_record_name' => $publishedHostname,
        'artifact_dns_zone_id' => 'zone-123',
    ])->save();
    config(['cloudflare.zone_id' => 'zone-456']);

    expect(fn () => app(CloudflareDnsService::class)->ensureAgentRecord($agent))
        ->toThrow(RuntimeException::class, 'zone changed');

    expect($agent->fresh())
        ->artifact_dns_record_id->toBe('rec_existing')
        ->artifact_dns_record_name->toBe($publishedHostname)
        ->artifact_dns_zone_id->toBe('zone-123');
    Http::assertNothingSent();
});

test('ensureAgentRecord throws when cloudflare is not configured', function () {
    config(['cloudflare.api_token' => null]);
    $agent = artifactAgent();

    expect(fn () => app(CloudflareDnsService::class)->ensureAgentRecord($agent))
        ->toThrow(RuntimeException::class);
});

test('removeAgentRecord fails closed when cleanup credentials are unavailable', function () {
    config(['cloudflare.zone_id' => null]);
    Http::fake();
    $agent = artifactAgent();

    expect(fn () => app(CloudflareDnsService::class)->removeAgentRecord($agent))
        ->toThrow(RuntimeException::class, 'Cloudflare DNS is not configured');

    Http::assertNothingSent();
});
