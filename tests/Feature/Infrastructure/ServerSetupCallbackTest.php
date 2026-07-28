<?php

use App\Enums\CloudProvider;
use App\Enums\ServerStatus;
use App\Models\Server;
use App\Services\AsciiBoxService;
use App\Services\CloudServiceFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('disables ascii auto-stop only when final server setup is ready', function () {
    $server = Server::factory()->ascii()->provisioning()->create([
        'provider_server_id' => 'bx_23456789',
    ]);

    $ascii = Mockery::mock(AsciiBoxService::class);
    $ascii->shouldReceive('disableAutoStop')
        ->once()
        ->with('bx_23456789');

    $factory = Mockery::mock(CloudServiceFactory::class);
    $factory->shouldReceive('makeFor')
        ->once()
        ->with(Mockery::on(fn ($team): bool => $team->is($server->team)), CloudProvider::Ascii)
        ->andReturn($ascii);
    app()->instance(CloudServiceFactory::class, $factory);

    $expiresAt = now()->addMinutes(30)->timestamp;
    $signature = hash_hmac(
        'sha256',
        "server-setup-callback|{$server->id}|{$expiresAt}",
        config('app.key'),
    );

    $this->postJson('/api/webhooks/server-setup', [
        'server_id' => $server->id,
        'status' => 'ready',
        'expires_at' => $expiresAt,
        'signature' => $signature,
    ])->assertOk();

    expect($server->fresh()->status)->toBe(ServerStatus::Running);
});
