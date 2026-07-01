<?php

namespace App\Http\Controllers\Api;

use App\Enums\ArtifactType;
use App\Enums\ArtifactVisibility;
use App\Http\Controllers\Controller;
use App\Models\AgentArtifact;
use App\Services\PublishArtifactService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ArtifactController extends Controller
{
    public function __construct(private PublishArtifactService $publisher) {}

    public function index(Request $request): JsonResponse
    {
        $agent = $request->input('authenticated_agent');

        return response()->json(
            $agent->artifacts()->orderByDesc('created_at')->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $agent = $request->input('authenticated_agent');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'path_slug' => ['nullable', 'string', 'max:60', 'regex:/^[a-z0-9][a-z0-9-]*$/'],
            'source_dir' => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9][a-z0-9._\/-]*$/'],
        ]);

        abort_if(! $agent->server_id, 422, 'Agent has no server to publish from.');

        $pathSlug = $data['path_slug'] ?? Str::slug($data['name']);

        $artifact = $this->publisher->publish($agent, [
            'name' => $data['name'],
            'path_slug' => $pathSlug,
            'type' => ArtifactType::Static,
            'source_dir' => $data['source_dir'] ?? $pathSlug,
            'visibility' => ArtifactVisibility::Public,
        ]);

        return response()->json($artifact, 201);
    }

    public function destroy(Request $request, AgentArtifact $artifact): JsonResponse
    {
        $agent = $request->input('authenticated_agent');

        abort_unless($artifact->agent_id === $agent->id, 404);

        $this->publisher->unpublish($artifact);

        return response()->json(['message' => 'Artifact unpublished.']);
    }
}
