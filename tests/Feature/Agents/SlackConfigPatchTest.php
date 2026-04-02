<?php

use App\Models\AgentSlackConnection;
use App\Services\ConfigPatchService;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

test('config patch uses custom slack settings', function () {
    $slack = AgentSlackConnection::factory()->connected()->create([
        'dm_policy' => 'disabled',
        'group_policy' => 'open',
        'require_mention' => true,
        'reply_to_mode' => 'all',
        'dm_session_scope' => 'per-peer',
    ]);

    $service = new ConfigPatchService;
    $patch = $service->buildSetSlackTokensPatch($slack);

    expect($patch)
        ->toContain("dmPolicy='disabled'")
        ->toContain("groupPolicy='open'")
        ->toContain('requireMention=true')
        ->toContain("replyToMode='all'")
        ->toContain("dmScope='per-peer'");
});

test('config patch uses default slack settings', function () {
    $slack = AgentSlackConnection::factory()->connected()->create();
    $slack = $slack->fresh();

    $service = new ConfigPatchService;
    $patch = $service->buildSetSlackTokensPatch($slack);

    expect($patch)
        ->toContain("dmPolicy='open'")
        ->toContain("groupPolicy='open'")
        ->toContain('requireMention=false')
        ->toContain("replyToMode='off'")
        ->toContain("dmScope='main'");
});
