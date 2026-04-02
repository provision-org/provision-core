<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Server;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

class AdminServerController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/servers/index', [
            'servers' => Server::query()
                ->with('team:id,name')
                ->withCount('agents')
                ->latest()
                ->paginate(25),
        ]);
    }

    public function rootPassword(Server $server): JsonResponse
    {
        return response()->json([
            'root_password' => $server->root_password,
            'ipv4_address' => $server->ipv4_address,
        ]);
    }
}
