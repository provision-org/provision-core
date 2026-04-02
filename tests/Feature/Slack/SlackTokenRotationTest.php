<?php

use App\Models\SlackConfigurationToken;
use App\Services\SlackApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('rotation refreshes expiring tokens', function () {
    Http::fake([
        'slack.com/api/tooling.tokens.rotate' => Http::response([
            'ok' => true,
            'token' => 'xoxe.xoxp-rotated',
            'refresh_token' => 'xoxe-rotated-refresh',
            'exp' => now()->addHours(12)->timestamp,
        ]),
    ]);

    $token = SlackConfigurationToken::factory()->expiringSoon()->create();

    $service = new SlackApiService;
    $result = $service->getValidConfigToken($token);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'tooling.tokens.rotate');
    });

    $token->refresh();
    expect($token->expires_at->isFuture())->toBeTrue();
});

test('skips tokens not expiring soon', function () {
    Http::fake();

    $token = SlackConfigurationToken::factory()->create([
        'expires_at' => now()->addHours(10),
    ]);

    $service = new SlackApiService;
    $result = $service->getValidConfigToken($token);

    Http::assertNothingSent();
});
