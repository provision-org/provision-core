<?php

namespace App\Services;

use App\Enums\ActorType;
use App\Models\AuditLog;

class AuditService
{
    /**
     * Create an immutable audit log entry.
     *
     * @param  array<string, mixed>|null  $payload
     */
    public function log(
        string $teamId,
        ActorType $actorType,
        string $actorId,
        string $action,
        ?string $targetType = null,
        ?string $targetId = null,
        ?array $payload = null,
    ): AuditLog {
        return AuditLog::query()->create([
            'team_id' => $teamId,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'payload' => $payload,
            'created_at' => now(),
        ]);
    }
}
