<?php

use App\Enums\ActorType;
use App\Models\AuditLog;
use App\Models\Team;
use App\Services\AuditService;

beforeEach(function () {
    $this->team = Team::factory()->create();
});

it('prevents updating audit log entries', function () {
    $entry = AuditLog::factory()->create([
        'team_id' => $this->team->id,
        'action' => 'task.created',
    ]);

    $entry->action = 'task.deleted';
    $entry->save();
})->throws(RuntimeException::class, 'Audit log entries are immutable and cannot be updated.');

it('prevents deleting audit log entries', function () {
    $entry = AuditLog::factory()->create([
        'team_id' => $this->team->id,
        'action' => 'task.created',
    ]);

    $entry->delete();
})->throws(RuntimeException::class, 'Audit log entries are immutable and cannot be deleted.');

it('creates audit log entries via AuditService', function () {
    $service = new AuditService;

    $entry = $service->log(
        teamId: $this->team->id,
        actorType: ActorType::User,
        actorId: 'user_123',
        action: 'task.created',
        targetType: 'task',
        targetId: '01kn_test_id',
        payload: ['title' => 'Test Task'],
    );

    expect($entry)->toBeInstanceOf(AuditLog::class)
        ->and($entry->team_id)->toBe($this->team->id)
        ->and($entry->actor_type)->toBe(ActorType::User)
        ->and($entry->actor_id)->toBe('user_123')
        ->and($entry->action)->toBe('task.created')
        ->and($entry->target_type)->toBe('task')
        ->and($entry->payload)->toBe(['title' => 'Test Task'])
        ->and($entry->created_at)->not->toBeNull();
});

it('creates audit log entries without optional fields', function () {
    $service = new AuditService;

    $entry = $service->log(
        teamId: $this->team->id,
        actorType: ActorType::System,
        actorId: 'system',
        action: 'governance.mode_changed',
    );

    expect($entry->target_type)->toBeNull()
        ->and($entry->target_id)->toBeNull()
        ->and($entry->payload)->toBeNull();
});

it('casts actor_type to ActorType enum', function () {
    $entry = AuditLog::factory()->create([
        'team_id' => $this->team->id,
        'actor_type' => ActorType::Agent,
        'actor_id' => 'agent_001',
        'action' => 'task.completed',
    ]);

    $entry->refresh();

    expect($entry->actor_type)->toBeInstanceOf(ActorType::class)
        ->and($entry->actor_type)->toBe(ActorType::Agent);
});

it('casts payload as array', function () {
    $entry = AuditLog::factory()->create([
        'team_id' => $this->team->id,
        'payload' => ['key' => 'value', 'nested' => ['a' => 1]],
    ]);

    $entry->refresh();

    expect($entry->payload)->toBeArray()
        ->and($entry->payload['key'])->toBe('value')
        ->and($entry->payload['nested']['a'])->toBe(1);
});
