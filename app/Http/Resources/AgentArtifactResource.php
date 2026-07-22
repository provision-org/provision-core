<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AgentArtifactResource extends JsonResource
{
    public static $wrap = null;

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'path_slug' => $this->path_slug,
            'type' => $this->type->value,
            'visibility' => $this->visibility->value,
            'status' => $this->status,
            'public_url' => $this->public_url,
            'last_published_at' => $this->last_published_at?->toISOString(),
        ];
    }
}
