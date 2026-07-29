<?php

use App\Services\HetznerService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

it('treats missing servers and volumes as already deleted', function () {
    Http::fake([
        'api.hetzner.cloud/*' => Http::response(['error' => ['code' => 'not_found']], 404),
    ]);

    $hetzner = new HetznerService('test-token');

    expect(fn () => $hetzner->deleteServer('12345'))
        ->not->toThrow(RequestException::class)
        ->and(fn () => $hetzner->deleteVolume('67890'))
        ->not->toThrow(RequestException::class);

    Http::assertSentCount(2);
});
