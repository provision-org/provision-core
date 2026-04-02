<?php

use App\Http\Middleware\AuthenticateAgentToken;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureHasTeam;
use App\Http\Middleware\EnsureProfileComplete;
use App\Http\Middleware\EnsureServerReady;
use App\Http\Middleware\EnsureUserActivated;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        then: function () {
            Route::middleware('web')
                ->group(base_path('routes/admin.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            SecurityHeaders::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'ensure-activated' => EnsureUserActivated::class,
            'ensure-profile-complete' => EnsureProfileComplete::class,
            'ensure-has-team' => EnsureHasTeam::class,
            'ensure-admin' => EnsureAdmin::class,
            'ensure-server-ready' => EnsureServerReady::class,
            'auth.agent-token' => AuthenticateAgentToken::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
