<?php

use App\Enums\ServerStatus;
use App\Jobs\SetupOpenClawOnServerJob;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function validCallbackParams(Server $server, string $status = 'ready'): array
{
    $expiresAt = now()->addMinutes(30)->timestamp;
    $signature = hash_hmac('sha256', $server->id.'|'.$expiresAt, config('app.key'));

    return [
        'server_id' => $server->id,
        'status' => $status,
        'signature' => $signature,
        'expires_at' => $expiresAt,
    ];
}

it('dispatches setup job on valid ready callback', function () {
    Queue::fake();

    $server = Server::factory()->create();
    $params = validCallbackParams($server, 'ready');

    $response = $this->postJson('/api/webhooks/server-ready', $params);

    $response->assertOk();
    Queue::assertPushed(SetupOpenClawOnServerJob::class, function ($job) use ($server) {
        return $job->server->id === $server->id;
    });
});

it('marks server as error on error callback', function () {
    Queue::fake();

    $server = Server::factory()->create();
    $params = validCallbackParams($server, 'error');

    $response = $this->postJson('/api/webhooks/server-ready', $params);

    $response->assertOk();
    $server->refresh();
    expect($server->status)->toBe(ServerStatus::Error);
});

it('creates a server_ready event on ready callback', function () {
    Queue::fake();

    $server = Server::factory()->create();
    $params = validCallbackParams($server, 'ready');

    $this->postJson('/api/webhooks/server-ready', $params);

    expect($server->events()->where('event', 'server_ready')->exists())->toBeTrue();
});

it('creates a provisioning_error event on error callback', function () {
    Queue::fake();

    $server = Server::factory()->create();
    $params = validCallbackParams($server, 'error');

    $this->postJson('/api/webhooks/server-ready', $params);

    expect($server->events()->where('event', 'provisioning_error')->exists())->toBeTrue();
});

it('returns 403 for invalid hmac signature', function () {
    $server = Server::factory()->create();

    $response = $this->postJson('/api/webhooks/server-ready', [
        'server_id' => $server->id,
        'status' => 'ready',
        'signature' => 'invalid-signature',
        'expires_at' => now()->addMinutes(30)->timestamp,
    ]);

    $response->assertForbidden();
});

it('returns 403 for expired signature', function () {
    $server = Server::factory()->create();
    $expiresAt = now()->subMinutes(5)->timestamp;
    $signature = hash_hmac('sha256', $server->id.'|'.$expiresAt, config('app.key'));

    $response = $this->postJson('/api/webhooks/server-ready', [
        'server_id' => $server->id,
        'status' => 'ready',
        'signature' => $signature,
        'expires_at' => $expiresAt,
    ]);

    $response->assertForbidden();
});

it('records progress event without changing server status', function () {
    $server = Server::factory()->provisioning()->create();
    $params = validCallbackParams($server, 'progress');
    $params['step'] = 'installing_packages';

    $response = $this->postJson('/api/webhooks/server-ready', $params);

    $response->assertOk();
    $server->refresh();
    expect($server->status)->toBe(ServerStatus::Provisioning);
    expect($server->events()->where('event', 'cloud_init_progress')->exists())->toBeTrue();
    expect($server->events()->where('event', 'cloud_init_progress')->first()->payload)->toBe(['step' => 'installing_packages']);
});

it('progress callback requires step parameter', function () {
    $server = Server::factory()->provisioning()->create();
    $params = validCallbackParams($server, 'progress');

    $response = $this->postJson('/api/webhooks/server-ready', $params);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('step');
});

it('returns 422 for missing parameters', function () {
    $response = $this->postJson('/api/webhooks/server-ready', []);

    $response->assertUnprocessable();
});
