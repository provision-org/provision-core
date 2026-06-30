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

    expect($id)->toBe('rec_new');

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && $request['type'] === 'A'
        && $request['name'] === 'luna.provisionagents.com'
        && $request['content'] === '203.0.113.7'
        && $request['proxied'] === false);
});

test('ensureAgentRecord is idempotent when the record already matches', function () {
    Http::fake(fn ($request) => $request->method() === 'GET'
        ? Http::response(['result' => [['id' => 'rec_existing', 'content' => '203.0.113.7']]])
        : Http::response([]));

    $agent = artifactAgent('203.0.113.7');
    $id = app(CloudflareDnsService::class)->ensureAgentRecord($agent);

    expect($id)->toBe('rec_existing');
    Http::assertNotSent(fn ($request) => $request->method() === 'POST');
});

test('ensureAgentRecord repoints an existing record when the ip changed', function () {
    Http::fake(function ($request) {
        return match ($request->method()) {
            'GET' => Http::response(['result' => [['id' => 'rec_existing', 'content' => '198.51.100.1']]]),
            'PATCH' => Http::response(['result' => ['id' => 'rec_existing']]),
            default => Http::response([]),
        };
    });

    $agent = artifactAgent('203.0.113.7');
    app(CloudflareDnsService::class)->ensureAgentRecord($agent);

    Http::assertSent(fn ($request) => $request->method() === 'PATCH'
        && $request['content'] === '203.0.113.7');
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
});

test('ensureAgentRecord throws when cloudflare is not configured', function () {
    config(['cloudflare.api_token' => null]);
    $agent = artifactAgent();

    expect(fn () => app(CloudflareDnsService::class)->ensureAgentRecord($agent))
        ->toThrow(RuntimeException::class);
});

test('removeAgentRecord is a no-op when not configured', function () {
    config(['cloudflare.zone_id' => null]);
    Http::fake();
    $agent = artifactAgent();

    app(CloudflareDnsService::class)->removeAgentRecord($agent);

    Http::assertNothingSent();
});
