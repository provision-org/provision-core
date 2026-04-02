<?php

use App\Http\Controllers\Settings\ApiTokenController;
use App\Http\Controllers\Settings\CurrentTeamController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\TeamController;
use App\Http\Controllers\Settings\TeamInvitationController;
use App\Http\Controllers\Settings\TeamMemberController;
use App\Http\Controllers\Settings\TwoFactorAuthenticationController;
use App\Http\Controllers\SlackConfigurationTokenController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');

    Route::put('settings/current-team', [CurrentTeamController::class, 'update'])->name('current-team.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/password', [PasswordController::class, 'edit'])->name('user-password.edit');

    Route::put('settings/password', [PasswordController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::get('settings/appearance', function () {
        return Inertia::render('settings/appearance');
    })->name('appearance.edit');

    Route::get('settings/two-factor', [TwoFactorAuthenticationController::class, 'show'])
        ->name('two-factor.show');

    Route::get('settings/api', [ApiTokenController::class, 'index'])->name('api-tokens.index');
    Route::post('settings/api', [ApiTokenController::class, 'store'])->name('api-tokens.store');
    Route::delete('settings/api/{token}', [ApiTokenController::class, 'destroy'])->name('api-tokens.destroy');
});

Route::middleware(['auth', 'verified', 'ensure-activated', 'ensure-profile-complete'])->group(function () {
    // Teams
    Route::get('settings/teams/create', [TeamController::class, 'create'])->name('teams.create');
    Route::post('settings/teams', [TeamController::class, 'store'])->name('teams.store');
    Route::post('settings/teams/scrape-company', [TeamController::class, 'scrapeCompany'])->name('teams.scrape-company');
    Route::get('settings/teams/{team}', [TeamController::class, 'show'])->name('teams.show');
    Route::get('settings/teams/{team}/provisioning', [TeamController::class, 'provisioning'])->name('teams.provisioning');
    Route::patch('settings/teams/{team}', [TeamController::class, 'update'])->name('teams.update');
    Route::delete('settings/teams/{team}', [TeamController::class, 'destroy'])->name('teams.destroy');

    // Slack Configuration Tokens
    Route::get('settings/teams/{team}/slack-config', [SlackConfigurationTokenController::class, 'create'])->name('teams.slack-config.create');
    Route::post('settings/teams/{team}/slack-config', [SlackConfigurationTokenController::class, 'store'])->name('teams.slack-config.store');
    Route::delete('settings/teams/{team}/slack-config', [SlackConfigurationTokenController::class, 'destroy'])->name('teams.slack-config.destroy');

    // Team Members
    Route::put('settings/teams/{team}/members/{user}', [TeamMemberController::class, 'update'])->name('team-members.update');
    Route::delete('settings/teams/{team}/members/{user}', [TeamMemberController::class, 'destroy'])->name('team-members.destroy');

    // Team Invitations
    Route::post('settings/teams/{team}/invitations', [TeamInvitationController::class, 'store'])->name('team-invitations.store');
    Route::get('team-invitations/{invitation}/accept', [TeamInvitationController::class, 'accept'])->name('team-invitations.accept');
    Route::delete('team-invitations/{invitation}', [TeamInvitationController::class, 'destroy'])->name('team-invitations.destroy');

    // API Keys (redirects to top-level routes)
    Route::redirect('settings/teams/{team}/api-keys', '/api-keys');

    // Environment Variables (redirects to top-level routes)
    Route::redirect('settings/teams/{team}/env-vars', '/api-keys');

    // Agents (redirects to top-level routes)
    Route::redirect('settings/teams/{team}/agents', '/agents');
    Route::redirect('settings/teams/{team}/agents/{agent}', '/agents/{agent}');
    Route::redirect('settings/teams/{team}/agents/{agent}/edit', '/agents/{agent}/edit');
    Route::redirect('settings/teams/{team}/agents/{agent}/slack', '/agents/{agent}/slack');
});
