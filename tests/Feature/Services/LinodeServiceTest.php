<?php

use App\Services\LinodeService;
use Illuminate\Support\Facades\Http;

it('detaches a volume with an empty JSON object body', function () {
    // Regression: a body-less POST serializes to `[]` (a JSON array), which
    // Linode rejects with 400 {"errors":[{"reason":"Invalid JSON"}]}. That
    // aborted every Linode teardown before it could delete the volume, the
    // firewall, or the team record.
    Http::fake(['api.linode.com/*' => Http::response([], 200)]);

    (new LinodeService('test-token'))->detachVolume(15226191);

    Http::assertSent(function ($request) {
        return $request->method() === 'POST'
            && str_contains($request->url(), '/volumes/15226191/detach')
            && $request->body() === '{}';
    });
});

it('treats a missing firewall as already deleted', function () {
    Http::fake(['api.linode.com/*' => Http::response(['errors' => [['reason' => 'Not found']]], 404)]);

    // Must not throw — a 404 means the firewall is already gone.
    expect(fn () => (new LinodeService('test-token'))->deleteFirewall(4148346))
        ->not->toThrow(Exception::class);
});
