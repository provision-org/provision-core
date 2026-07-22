<?php

namespace App\Http\Controllers\Api;

use App\Enums\ArtifactType;
use App\Enums\ArtifactVisibility;
use App\Enums\HarnessType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreArtifactRequest;
use App\Http\Resources\AgentArtifactResource;
use App\Models\AgentArtifact;
use App\Services\PublishArtifactService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ArtifactController extends Controller
{
    public function __construct(private PublishArtifactService $publisher) {}

    public function index(Request $request): JsonResponse
    {
        $agent = $request->input('authenticated_agent');

        return AgentArtifactResource::collection(
            $agent->artifacts()->orderByDesc('created_at')->get(),
        )->response();
    }

    public function store(StoreArtifactRequest $request): JsonResponse
    {
        $agent = $request->input('authenticated_agent');
        $data = $request->validated();

        abort_unless($agent->server && ! $agent->server->isDocker(), 422, 'Artifact publishing requires a provisioned remote server.');
        abort_unless($agent->harness_type === HarnessType::OpenClaw, 422, 'Artifact publishing requires an OpenClaw agent.');
        abort_unless($this->publisher->isConfigured(), 503, 'Artifact publishing is not configured.');

        $type = isset($data['type']) ? ArtifactType::from($data['type']) : ArtifactType::Static;
        $visibility = isset($data['visibility'])
            ? ArtifactVisibility::from($data['visibility'])
            : ArtifactVisibility::Public;

        $artifact = $this->publisher->publish($agent, [
            'name' => $data['name'],
            'path_slug' => $data['path_slug'],
            'type' => $type,
            'source_dir' => $data['source_dir'] ?? $data['path_slug'],
            'start_command' => $data['start_command'] ?? null,
            'visibility' => $visibility,
        ]);

        return (new AgentArtifactResource($artifact))
            ->response()
            ->setStatusCode(201);
    }

    public function destroy(Request $request, AgentArtifact $artifact): JsonResponse
    {
        $agent = $request->input('authenticated_agent');

        abort_unless($artifact->agent_id === $agent->id, 404);

        $this->publisher->unpublish($artifact);

        return response()->json(['message' => 'Artifact unpublished.']);
    }
}
