<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use Inertia\Inertia;
use Inertia\Response;

class AdminAgentController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/agents/index', [
            'agents' => Agent::query()
                ->with([
                    'team:id,name,user_id',
                    'team.owner:id,name',
                    'server:id,ipv4_address',
                    'slackConnection:id,agent_id,status',
                    'emailConnection:id,agent_id,status',
                    'telegramConnection:id,agent_id,status',
                    'discordConnection:id,agent_id,status',
                ])
                ->latest()
                ->paginate(25),
        ]);
    }
}
