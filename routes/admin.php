<?php

use App\Http\Controllers\Admin\AdminAgentController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminServerController;
use App\Http\Controllers\Admin\AdminTeamController;
use App\Http\Controllers\Admin\AdminUserController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'ensure-admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::redirect('/', '/admin/dashboard');

    Route::get('dashboard', [AdminDashboardController::class, 'index'])->name('dashboard');

    Route::get('users', [AdminUserController::class, 'index'])->name('users.index');
    Route::get('users/{user}', [AdminUserController::class, 'show'])->name('users.show');
    Route::post('users/{user}/activate', [AdminUserController::class, 'activate'])->name('users.activate');
    Route::post('users/{user}/deactivate', [AdminUserController::class, 'deactivate'])->name('users.deactivate');

    Route::get('agents', [AdminAgentController::class, 'index'])->name('agents.index');

    Route::get('teams', [AdminTeamController::class, 'index'])->name('teams.index');
    Route::post('teams/{team}/grant-credits', [AdminTeamController::class, 'grantCredits'])->name('teams.grant-credits');

    Route::get('servers', [AdminServerController::class, 'index'])->name('servers.index');
    Route::get('servers/{server}/root-password', [AdminServerController::class, 'rootPassword'])->name('servers.root-password');
});
