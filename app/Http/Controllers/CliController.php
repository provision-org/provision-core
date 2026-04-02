<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CliController extends Controller
{
    public function whoami(Request $request): JsonResponse
    {
        $user = $request->user();
        $team = $user->currentTeam;

        return response()->json([
            'name' => $user->name,
            'email' => $user->email,
            'team' => $team?->name,
            'team_id' => $team?->id,
        ]);
    }

    public function listAgents(Request $request): JsonResponse
    {
        $team = $request->user()->currentTeam;
        abort_unless($team, 403);

        $agents = $team->agents()
            ->select('id', 'name', 'role', 'status')
            ->get();

        return response()->json($agents);
    }
}
