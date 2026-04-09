<?php

use App\Http\Controllers\AgentActivityController;
use App\Http\Controllers\AgentController;
use App\Http\Controllers\AgentEnvController;
use App\Http\Controllers\AgentMemoryController;
use App\Http\Controllers\AgentScheduleController;
use App\Http\Controllers\AgentWorkspaceController;
use App\Http\Controllers\ApiKeyController;
use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\CliAuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DiscordConnectionController;
use App\Http\Controllers\GoalController;
use App\Http\Controllers\GovernanceTaskController;
use App\Http\Controllers\OrgChartController;
use App\Http\Controllers\ProfileSetupController;
use App\Http\Controllers\RoutineController;
use App\Http\Controllers\SharedWorkspaceController;
use App\Http\Controllers\SlackConnectionController;
use App\Http\Controllers\TaskNoteController;
use App\Http\Controllers\TaskWorkProductController;
use App\Http\Controllers\TeamPackController;
use App\Http\Controllers\TelegramConnectionController;
use App\Http\Controllers\UsageController;
use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return redirect('/login');
})->name('home');

// CLI auth routes (cli landing page moved to provision/module-skills)

// Google OAuth
Route::get('auth/google', [GoogleController::class, 'redirect'])->name('auth.google');
Route::get('auth/google/callback', [GoogleController::class, 'callback'])->name('auth.google.callback');

// CLI authentication (requires login but not team/subscription)
Route::middleware('auth')->group(function () {
    Route::get('auth/cli', [CliAuthController::class, 'show'])->name('cli.auth');
    Route::post('auth/cli', [CliAuthController::class, 'authorize'])->name('cli.authorize');
    Route::get('auth/extension', [CliAuthController::class, 'extensionAuth'])->name('extension.auth');
    Route::post('auth/extension', [CliAuthController::class, 'extensionAuthorize'])->name('extension.authorize');
    Route::get('auth/extension/callback', fn () => Inertia::render('auth/extension-callback'))->name('extension.callback');
});

Route::middleware(['auth', 'verified', 'ensure-activated', 'ensure-has-team'])->group(function () {
    Route::get('dashboard', [DashboardController::class, '__invoke'])->name('dashboard');
    Route::get('dashboard/usage-chart', [DashboardController::class, 'usageChart'])->name('dashboard.usage-chart');
});

Route::get('waitlist', function () {
    if (auth()->user()->isActivated()) {
        return redirect()->route('dashboard');
    }

    return Inertia::render('waitlist', [
        'calendlyUrl' => config('services.calendly.url'),
    ]);
})->middleware(['auth', 'verified'])->name('waitlist');

Route::middleware(['auth', 'verified', 'ensure-activated'])->group(function () {
    // Profile setup (outside ensure-profile-complete and ensure-has-team)
    Route::get('profile-setup', [ProfileSetupController::class, 'show'])->name('profile-setup');
    Route::post('profile-setup', [ProfileSetupController::class, 'store'])->name('profile-setup.store');

    Route::get('slack/oauth/callback', [SlackConnectionController::class, 'oauthCallback'])->name('slack.oauth.callback');
});

Route::middleware(['auth', 'verified', 'ensure-activated', 'ensure-has-team'])->group(function () {
    // Skills routes registered by provision/module-skills package (if installed)

    // Server management
    Route::post('server/restart-gateway', [AgentController::class, 'restartGateway'])->name('server.restart-gateway');

    // Agent routes require the server to be ready
    Route::middleware('ensure-server-ready')->group(function () {
        Route::get('agents', [AgentController::class, 'index'])->name('agents.index');
        Route::get('agents/library', [AgentController::class, 'library'])->name('agents.library');
        Route::get('agents/create', [AgentController::class, 'create'])->name('agents.create');
        Route::get('agents/templates/{role}', [AgentController::class, 'template'])->name('agents.template');
        Route::get('agents/library/{agentTemplate}/details', [AgentController::class, 'templateDetails'])->name('agents.template-details');
        Route::post('agents/hire/{agentTemplate}', [AgentController::class, 'hire'])->name('agents.hire');
        Route::post('agents', [AgentController::class, 'store'])->name('agents.store')->middleware(HandlePrecognitiveRequests::class);
        Route::get('agents/{agent}/setup', [AgentController::class, 'setup'])->name('agents.setup');
        Route::get('agents/{agent}/provisioning', [AgentController::class, 'provisioning'])->name('agents.provisioning');
        Route::get('agents/{agent}', [AgentController::class, 'show'])->name('agents.show');
        Route::get('agents/{agent}/configure', [AgentController::class, 'configure'])->name('agents.configure');
        Route::get('agents/{agent}/edit', [AgentController::class, 'edit'])->name('agents.edit');
        Route::patch('agents/{agent}', [AgentController::class, 'update'])->name('agents.update');
        Route::post('agents/{agent}/retry', [AgentController::class, 'retry'])->name('agents.retry');
        Route::post('agents/{agent}/resync-channels', [AgentController::class, 'resyncChannels'])->name('agents.resync-channels');
        Route::delete('agents/{agent}', [AgentController::class, 'destroy'])->name('agents.destroy');
        Route::get('agents/{agent}/browser', [AgentController::class, 'browser'])->name('agents.browser')->middleware('signed');
        Route::get('agents/{agent}/logs', [AgentController::class, 'logs'])->name('agents.logs');
        Route::get('agents/{agent}/usage-chart', [AgentController::class, 'usageChart'])->name('agents.usage-chart');
        Route::get('agents/{agent}/inbox', [AgentController::class, 'inbox'])->name('agents.inbox');
        Route::get('agents/{agent}/inbox/{messageId}', [AgentController::class, 'inboxMessage'])->name('agents.inbox.message');

        // Agent activity feed
        Route::get('agents/{agent}/activities', [AgentActivityController::class, 'forAgent'])->name('agents.activities');

        // Agent schedules (cron management)
        Route::get('agents/{agent}/schedules', [AgentScheduleController::class, 'index'])->name('agents.schedules.index');
        Route::post('agents/{agent}/schedules', [AgentScheduleController::class, 'store'])->name('agents.schedules.store');
        Route::patch('agents/{agent}/schedules/{cronId}', [AgentScheduleController::class, 'update'])->name('agents.schedules.update');
        Route::delete('agents/{agent}/schedules/{cronId}', [AgentScheduleController::class, 'destroy'])->name('agents.schedules.destroy');
        Route::patch('agents/{agent}/schedules/{cronId}/toggle', [AgentScheduleController::class, 'toggle'])->name('agents.schedules.toggle');
        Route::post('agents/{agent}/schedules/{cronId}/run', [AgentScheduleController::class, 'run'])->name('agents.schedules.run');

        // Agent environment variables
        Route::get('agents/{agent}/env', [AgentEnvController::class, 'show'])->name('agents.env.show');
        Route::put('agents/{agent}/env', [AgentEnvController::class, 'update'])->name('agents.env.update');

        // Agent workspace
        Route::get('agents/{agent}/workspace', [AgentWorkspaceController::class, 'index'])->name('agents.workspace.index');
        Route::post('agents/{agent}/workspace/upload', [AgentWorkspaceController::class, 'upload'])->name('agents.workspace.upload');
        Route::post('agents/{agent}/workspace/folder', [AgentWorkspaceController::class, 'createFolder'])->name('agents.workspace.folder');
        Route::delete('agents/{agent}/workspace', [AgentWorkspaceController::class, 'destroy'])->name('agents.workspace.destroy');
        Route::get('agents/{agent}/workspace/download', [AgentWorkspaceController::class, 'download'])->name('agents.workspace.download');

        // Agent memory
        Route::get('agents/{agent}/memory', [AgentMemoryController::class, 'index'])->name('agents.memory.index');
        Route::get('agents/{agent}/memory/{filename}', [AgentMemoryController::class, 'show'])->name('agents.memory.show');
        Route::put('agents/{agent}/memory/{filename}', [AgentMemoryController::class, 'update'])->name('agents.memory.update');

        // Team packs — bulk hire
        Route::post('agents/packs/{teamPack}/hire', [TeamPackController::class, 'hire'])->name('agents.packs.hire');

        Route::get('agents/{agent}/slack', [SlackConnectionController::class, 'create'])->name('agents.slack.create');
        Route::post('agents/{agent}/slack', [SlackConnectionController::class, 'storeLegacy'])->name('agents.slack.store');
        Route::post('agents/{agent}/slack/create-app', [SlackConnectionController::class, 'initiateApp'])->name('agents.slack.initiate-app');
        Route::post('agents/{agent}/slack/app-token', [SlackConnectionController::class, 'store'])->name('agents.slack.store-app-token');
        Route::post('agents/{agent}/slack/preferences', [SlackConnectionController::class, 'storePreferences'])->name('agents.slack.store-preferences');
        Route::patch('agents/{agent}/slack/settings', [SlackConnectionController::class, 'updateSettings'])->name('agents.slack.update-settings');
        Route::delete('agents/{agent}/slack', [SlackConnectionController::class, 'destroy'])->name('agents.slack.destroy');

        // Agent chat
        Route::get('agents/{agent}/chat', [ChatController::class, 'index'])->name('agents.chat');
        Route::get('agents/{agent}/chat/conversations', [ChatController::class, 'conversations'])->name('agents.chat.conversations');
        Route::post('agents/{agent}/chat', [ChatController::class, 'store'])->name('agents.chat.store');
        Route::get('agents/{agent}/chat/{conversation}', [ChatController::class, 'show'])->name('agents.chat.show');
        Route::post('agents/{agent}/chat/{conversation}', [ChatController::class, 'sendMessage'])->name('agents.chat.send');
        Route::post('agents/{agent}/chat/{conversation}/stream', [ChatController::class, 'stream'])->name('agents.chat.stream');
        Route::get('chat-attachments/{conversation}/{filename}', [ChatController::class, 'attachment'])->name('agents.chat.attachment')->middleware('signed');

        Route::get('agents/{agent}/channels', [AgentController::class, 'channels'])->name('agents.channels');

        Route::get('agents/{agent}/telegram', [TelegramConnectionController::class, 'create'])->name('agents.telegram.create');
        Route::post('agents/{agent}/telegram', [TelegramConnectionController::class, 'store'])->name('agents.telegram.store');
        Route::delete('agents/{agent}/telegram', [TelegramConnectionController::class, 'destroy'])->name('agents.telegram.destroy');

        Route::get('agents/{agent}/discord', [DiscordConnectionController::class, 'create'])->name('agents.discord.create');
        Route::post('agents/{agent}/discord', [DiscordConnectionController::class, 'store'])->name('agents.discord.store');
        Route::delete('agents/{agent}/discord', [DiscordConnectionController::class, 'destroy'])->name('agents.discord.destroy');
    });

    // API Keys
    Route::get('api-keys', [ApiKeyController::class, 'index'])->name('api-keys.index');
    Route::post('api-keys', [ApiKeyController::class, 'store'])->name('api-keys.store');
    Route::patch('api-keys/{apiKey}', [ApiKeyController::class, 'update'])->name('api-keys.update');
    Route::delete('api-keys/{apiKey}', [ApiKeyController::class, 'destroy'])->name('api-keys.destroy');

    // Environment Variables
    Route::post('api-keys/env-vars', [ApiKeyController::class, 'storeEnvVar'])->name('api-keys.env-vars.store');
    Route::patch('api-keys/env-vars/{envVar}', [ApiKeyController::class, 'updateEnvVar'])->name('api-keys.env-vars.update');
    Route::delete('api-keys/env-vars/{envVar}', [ApiKeyController::class, 'destroyEnvVar'])->name('api-keys.env-vars.destroy');

    // Governance (team resolved from authenticated user's currentTeam)
    Route::get('company/tasks', [GovernanceTaskController::class, 'index'])->name('company.tasks.index');
    Route::post('company/tasks', [GovernanceTaskController::class, 'store'])->name('company.tasks.store');
    Route::get('company/tasks/{task}', [GovernanceTaskController::class, 'show'])->name('company.tasks.show');
    Route::patch('company/tasks/{task}', [GovernanceTaskController::class, 'update'])->name('company.tasks.update');
    Route::delete('company/tasks/{task}', [GovernanceTaskController::class, 'destroy'])->name('company.tasks.destroy');
    Route::post('company/tasks/{task}/notes', [TaskNoteController::class, 'store'])->name('company.tasks.notes.store');
    Route::get('company/tasks/{task}/work-products/{taskWorkProduct}/download', [TaskWorkProductController::class, 'download'])->name('company.tasks.work-products.download');

    Route::get('company/org', [OrgChartController::class, 'index'])->name('company.org.index');
    Route::patch('company/agents/{agent}/reporting', [OrgChartController::class, 'updateReporting'])->name('company.org.updateReporting');

    Route::get('company/goals', [GoalController::class, 'index'])->name('company.goals.index');
    Route::post('company/goals', [GoalController::class, 'store'])->name('company.goals.store');
    Route::patch('company/goals/{goal}', [GoalController::class, 'update'])->name('company.goals.update');
    Route::delete('company/goals/{goal}', [GoalController::class, 'destroy'])->name('company.goals.destroy');

    Route::get('company/routines', [RoutineController::class, 'index'])->name('company.routines.index');
    Route::post('company/routines', [RoutineController::class, 'store'])->name('company.routines.store');
    Route::put('company/routines/{routine}', [RoutineController::class, 'update'])->name('company.routines.update');
    Route::post('company/routines/{routine}/toggle', [RoutineController::class, 'toggle'])->name('company.routines.toggle');
    Route::delete('company/routines/{routine}', [RoutineController::class, 'destroy'])->name('company.routines.destroy');

    Route::get('company/approvals', [ApprovalController::class, 'index'])->name('company.approvals.index');
    Route::get('company/approvals/{approval}', [ApprovalController::class, 'show'])->name('company.approvals.show');
    Route::post('company/approvals/{approval}/approve', [ApprovalController::class, 'approve'])->name('company.approvals.approve');
    Route::post('company/approvals/{approval}/reject', [ApprovalController::class, 'reject'])->name('company.approvals.reject');
    Route::post('company/approvals/{approval}/request-revision', [ApprovalController::class, 'requestRevision'])->name('company.approvals.requestRevision');

    Route::get('company/usage', [UsageController::class, 'index'])->name('company.usage.index');
    Route::get('company/agents/{agent}/usage', [UsageController::class, 'forAgent'])->name('company.usage.forAgent');

    Route::get('company/audit', [AuditLogController::class, 'index'])->name('company.audit.index');

    // Shared workspace
    Route::get('company/workspace', [SharedWorkspaceController::class, 'index'])->name('company.workspace.index');
    Route::post('company/workspace/upload', [SharedWorkspaceController::class, 'upload'])->name('company.workspace.upload');
    Route::post('company/workspace/folder', [SharedWorkspaceController::class, 'createFolder'])->name('company.workspace.folder');
    Route::delete('company/workspace', [SharedWorkspaceController::class, 'destroy'])->name('company.workspace.destroy');
    Route::get('company/workspace/download', [SharedWorkspaceController::class, 'download'])->name('company.workspace.download');
});

require __DIR__.'/settings.php';
