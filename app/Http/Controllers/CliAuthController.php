<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CliAuthController extends Controller
{
    public function show(Request $request): Response
    {
        $state = $request->query('state');
        $port = $request->query('port', '9876');

        abort_unless($state && strlen($state) >= 16, 400, 'Invalid state parameter');

        return Inertia::render('auth/cli', [
            'state' => $state,
            'port' => $port,
        ]);
    }

    public function authorize(Request $request): JsonResponse
    {
        $request->validate([
            'state' => 'required|string|min:16',
            'port' => 'required|integer|min:1024|max:65535',
        ]);

        $user = $request->user();
        $token = $user->createToken('provision-cli')->plainTextToken;

        return response()->json([
            'token' => $token,
            'state' => $request->state,
            'port' => $request->port,
        ]);
    }

    public function extensionAuth(Request $request): Response
    {
        return Inertia::render('auth/extension');
    }

    public function extensionAuthorize(Request $request): JsonResponse
    {
        $user = $request->user();
        $token = $user->createToken('provision-extension')->plainTextToken;

        return response()->json([
            'token' => $token,
            'name' => $user->name,
            'email' => $user->email,
        ]);
    }
}
