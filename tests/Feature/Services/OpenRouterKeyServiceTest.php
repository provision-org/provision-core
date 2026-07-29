<?php

use App\Services\OpenRouterKeyService;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

afterEach(function () {
    Sleep::fake(false);
});

it('treats a missing key as already deleted', function () {
    Http::fake([
        'openrouter.ai/*' => Http::response(['error' => ['message' => 'Not found']], 404),
    ]);

    expect(fn () => (new OpenRouterKeyService)->deleteKey('missing-hash'))
        ->not->toThrow(RequestException::class);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && $request->url() === 'https://openrouter.ai/api/v1/keys/missing-hash');
});

it('retries transient openrouter deletion failures', function () {
    Sleep::fake();
    Http::fakeSequence()
        ->push(['error' => ['message' => 'Unavailable']], 503)
        ->push(['error' => ['message' => 'Rate limited']], 429)
        ->push([], 204);

    expect(fn () => (new OpenRouterKeyService)->deleteKey('retry-hash'))
        ->not->toThrow(RequestException::class);

    Http::assertSentCount(3);
});

it('does not retry a permanent openrouter deletion failure', function () {
    Sleep::fake();
    Http::fake([
        'openrouter.ai/*' => Http::response(['error' => ['message' => 'Invalid key hash']], 422),
    ]);

    expect(fn () => (new OpenRouterKeyService)->deleteKey('invalid-hash'))
        ->toThrow(RequestException::class);

    Http::assertSentCount(1);
});
