<?php

use App\Enums\ApprovalStatus;
use App\Enums\ApprovalType;
use App\Models\Agent;
use App\Models\Approval;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonInterface;

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->agent = Agent::factory()->create(['team_id' => $this->team->id]);
});

it('creates a pending approval', function () {
    $approval = Approval::factory()->create([
        'team_id' => $this->team->id,
        'requesting_agent_id' => $this->agent->id,
        'type' => ApprovalType::HireAgent,
    ]);

    expect($approval->isPending())->toBeTrue()
        ->and($approval->isResolved())->toBeFalse()
        ->and($approval->type)->toBe(ApprovalType::HireAgent);
});

it('approves a pending approval', function () {
    $user = User::factory()->create();
    $approval = Approval::factory()->create([
        'team_id' => $this->team->id,
        'requesting_agent_id' => $this->agent->id,
    ]);

    $approval->update([
        'status' => ApprovalStatus::Approved,
        'reviewed_by' => $user->id,
        'reviewed_at' => now(),
        'review_note' => 'Looks good',
    ]);

    expect($approval->isResolved())->toBeTrue()
        ->and($approval->isPending())->toBeFalse()
        ->and($approval->status)->toBe(ApprovalStatus::Approved)
        ->and($approval->reviewedBy->id)->toBe($user->id);
});

it('rejects a pending approval', function () {
    $user = User::factory()->create();
    $approval = Approval::factory()->create([
        'team_id' => $this->team->id,
        'requesting_agent_id' => $this->agent->id,
    ]);

    $approval->update([
        'status' => ApprovalStatus::Rejected,
        'reviewed_by' => $user->id,
        'reviewed_at' => now(),
        'review_note' => 'Not allowed',
    ]);

    expect($approval->isResolved())->toBeTrue()
        ->and($approval->status)->toBe(ApprovalStatus::Rejected);
});

it('requests revision on an approval', function () {
    $approval = Approval::factory()->create([
        'team_id' => $this->team->id,
        'requesting_agent_id' => $this->agent->id,
    ]);

    $approval->update([
        'status' => ApprovalStatus::RevisionRequested,
        'review_note' => 'Please clarify the scope',
    ]);

    expect($approval->status)->toBe(ApprovalStatus::RevisionRequested)
        ->and($approval->isPending())->toBeFalse()
        ->and($approval->isResolved())->toBeFalse();
});

it('scopes pending approvals', function () {
    Approval::factory()->create([
        'team_id' => $this->team->id,
        'requesting_agent_id' => $this->agent->id,
        'status' => ApprovalStatus::Pending,
    ]);

    Approval::factory()->approved()->create([
        'team_id' => $this->team->id,
        'requesting_agent_id' => $this->agent->id,
    ]);

    expect(Approval::query()->pending()->count())->toBe(1);
});

it('scopes approvals for a specific agent', function () {
    $otherAgent = Agent::factory()->create(['team_id' => $this->team->id]);

    Approval::factory()->create([
        'team_id' => $this->team->id,
        'requesting_agent_id' => $this->agent->id,
    ]);

    Approval::factory()->create([
        'team_id' => $this->team->id,
        'requesting_agent_id' => $otherAgent->id,
    ]);

    expect(Approval::query()->forAgent($this->agent->id)->count())->toBe(1);
});

it('supports expiry timestamps', function () {
    $approval = Approval::factory()->hireAgent()->create([
        'team_id' => $this->team->id,
        'requesting_agent_id' => $this->agent->id,
    ]);

    expect($approval->expires_at)->not->toBeNull()
        ->and($approval->expires_at)->toBeInstanceOf(CarbonInterface::class);
});
