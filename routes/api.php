<?php

use App\Http\Controllers\Api\AgentInstallScriptController;
use App\Http\Controllers\Api\AgentUpdateCallbackController;
use App\Http\Controllers\Api\AgentUpdateScriptController;
use App\Http\Controllers\Api\DaemonController;
use App\Http\Controllers\Api\HermesInstallScriptController;
use App\Http\Controllers\Api\ServerCallbackController;
use App\Http\Controllers\Api\ServerSetupCallbackController;
use App\Http\Controllers\Api\ServerSetupScriptController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\WebChannelController;
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

// Agent API — authenticated via agent API token
Route::prefix('tasks')->middleware('auth.agent-token')->group(function () {
    Route::get('/', [TaskController::class, 'index']);
    Route::post('/', [TaskController::class, 'store']);
    Route::get('/next', [TaskController::class, 'next']);
    Route::get('/team-agents', [TaskController::class, 'teamAgents']);
    Route::get('/{task}', [TaskController::class, 'show']);
    Route::patch('/{task}', [TaskController::class, 'update']);
    Route::patch('/{task}/claim', [TaskController::class, 'claim']);
    Route::patch('/{task}/unclaim', [TaskController::class, 'unclaim']);
    Route::patch('/{task}/complete', [TaskController::class, 'complete']);
    Route::patch('/{task}/block', [TaskController::class, 'block']);
    Route::post('/{task}/notes', [TaskController::class, 'addNote']);
});

// Web channel — authenticated per-account via HMAC (inbound) or bearer (stream/probe)
Route::prefix('agents/web-channel')->group(function () {
    Route::post('/inbound', [WebChannelController::class, 'inbound'])
        ->name('api.agents.web-channel.inbound');
    Route::post('/activity', [WebChannelController::class, 'activity'])
        ->name('api.agents.web-channel.activity');
    Route::post('/stream-chunk', [WebChannelController::class, 'streamChunk'])
        ->name('api.agents.web-channel.stream-chunk');
    Route::get('/{accountId}/stream', [WebChannelController::class, 'stream'])
        ->name('api.agents.web-channel.stream');
    Route::get('/{accountId}/probe', [WebChannelController::class, 'probe'])
        ->name('api.agents.web-channel.probe');
    Route::post('/{accountId}/upload', [WebChannelController::class, 'upload'])
        ->name('api.agents.web-channel.upload');
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
