<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

class AdminDashboardController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/dashboard', [
            'stats' => [
                'total_users' => User::query()->count(),
                'activated_users' => User::query()->whereNotNull('activated_at')->count(),
                'waitlisted_users' => User::query()->whereNull('activated_at')->count(),
                'total_teams' => Team::query()->count(),
                'total_agents' => Agent::query()->count(),
                'agents_by_status' => Agent::query()
                    ->selectRaw('status, count(*) as count')
                    ->groupBy('status')
                    ->pluck('count', 'status'),
                'total_servers' => Server::query()->count(),
                'servers_by_status' => Server::query()
                    ->selectRaw('status, count(*) as count')
                    ->groupBy('status')
                    ->pluck('count', 'status'),
            ],
            'recentSignups' => User::query()
                ->latest()
                ->limit(10)
                ->get(['id', 'name', 'email', 'activated_at', 'created_at']),
        ]);
    }
}
