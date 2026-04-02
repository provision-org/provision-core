<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class AdminUserController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/users/index', [
            'users' => User::query()
                ->withCount('teams')
                ->latest()
                ->paginate(25),
        ]);
    }

    public function show(User $user): Response
    {
        return Inertia::render('admin/users/show', [
            'user' => $user->loadCount('teams'),
            'teams' => $user->teams()
                ->withCount(['agents', 'members'])
                ->with(['owner:id,name', 'server:id,team_id,status'])
                ->get(),
            'agents' => Agent::query()
                ->whereIn('team_id', $user->teams()->pluck('teams.id'))
                ->with('team:id,name')
                ->get(['id', 'name', 'role', 'status', 'team_id', 'created_at']),
        ]);
    }

    public function activate(User $user): RedirectResponse
    {
        $user->activate();

        return back();
    }

    public function deactivate(User $user): RedirectResponse
    {
        $user->deactivate();

        return back();
    }
}
