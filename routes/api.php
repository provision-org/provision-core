<?php

use App\Http\Controllers\Api\AgentInstallScriptController;
use App\Http\Controllers\Api\AgentUpdateCallbackController;
use App\Http\Controllers\Api\AgentUpdateScriptController;
use App\Http\Controllers\Api\DaemonController;
use App\Http\Controllers\Api\HermesInstallScriptController;
use App\Http\Controllers\Api\ServerCallbackController;
use App\Http\Controllers\Api\ServerSetupCallbackController;
use App\Http\Controllers\Api\ServerSetupScriptController;
use App\Http\Controllers\CliController;
use Illuminate\Support\Facades\Route;

// Webhooks — HMAC-signed, rate-limited
Route::middleware('throttle:60,1')->group(function () {
    Route::post('/webhooks/server-ready', ServerCallbackController::class)
        ->name('api.webhooks.server-ready');
    Route::post('/webhooks/agent-ready', [AgentInstallScriptController::class, 'callback'])
        ->name('api.webhooks.agent-ready');
    Route::post('/webhooks/server-setup', ServerSetupCallbackController::class)
        ->name('api.webhooks.server-setup');
    Route::post('/webhooks/agent-update', AgentUpdateCallbackController::class)
        ->name('api.webhooks.agent-update');
});

// Script endpoints — HMAC-signed, rate-limited
Route::middleware('throttle:30,1')->group(function () {
    Route::get('/agents/{agent}/install-script', [AgentInstallScriptController::class, 'show'])
        ->name('api.agents.install-script');
    Route::get('/servers/{server}/setup-script', [ServerSetupScriptController::class, 'show'])
        ->name('api.servers.setup-script');
    Route::get('/agents/{agent}/hermes-install-script', [HermesInstallScriptController::class, 'show'])
        ->name('api.agents.hermes-install-script');
    Route::get('/agents/{agent}/update-script', [AgentUpdateScriptController::class, 'show'])
        ->name('api.agents.update-script');
});

Route::prefix('cli')->middleware(['auth:sanctum'])->group(function () {
    Route::get('/whoami', [CliController::class, 'whoami']);
    Route::get('/agents', [CliController::class, 'listAgents']);
    // Skill routes registered by provision/module-skills package (if installed)
});

// Daemon API — authenticated via server daemon_token
Route::prefix('daemon/{token}')->middleware('daemon.token')->group(function () {
    Route::get('work-queue', [DaemonController::class, 'workQueue']);
    Route::post('tasks/{task}/checkout', [DaemonController::class, 'checkoutTask']);
    Route::post('tasks/{task}/result', [DaemonController::class, 'reportResult']);
    Route::post('tasks/{task}/release', [DaemonController::class, 'releaseTask']);
    Route::get('resolved-approvals', [DaemonController::class, 'resolvedApprovals']);
    Route::post('usage-events', [DaemonController::class, 'reportUsage']);
    Route::post('tasks/{task}/notes', [DaemonController::class, 'postNote']);
    Route::post('heartbeat', [DaemonController::class, 'heartbeat']);
});
