<?php

use App\Models\AgentSlackConnection;
use App\Services\ConfigPatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Pull the merge-patch JSON back out of the generated shell command
 * (PATCH_RAW='<json>'; ...) so assertions run on structure, not on shell
 * escaping details.
 */
function decodeSlackPatch(string $command): array
{
    expect(preg_match("/^PATCH_RAW='(.+?)';/", $command, $m))->toBe(1);

    return json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);
}

test('config patch uses custom slack settings', function () {
    $slack = AgentSlackConnection::factory()->connected()->create([
        'dm_policy' => 'disabled',
        'group_policy' => 'open',
        'require_mention' => true,
        'reply_to_mode' => 'all',
        'dm_session_scope' => 'per-peer',
    ]);

    $service = new ConfigPatchService;
    $patch = decodeSlackPatch($service->buildSetSlackTokensPatch($slack));

    expect($patch['channels']['slack'])
        ->toMatchArray([
            'enabled' => true,
            'dmPolicy' => 'disabled',
            'groupPolicy' => 'open',
            'requireMention' => true,
            'replyToMode' => 'all',
        ])
        ->and($patch['session']['dmScope'])->toBe('per-peer');
});

test('config patch uses default slack settings', function () {
    $slack = AgentSlackConnection::factory()->connected()->create();
    $slack = $slack->fresh();

    $service = new ConfigPatchService;
    $patch = decodeSlackPatch($service->buildSetSlackTokensPatch($slack));

    expect($patch['channels']['slack'])
        ->toMatchArray([
            'dmPolicy' => 'open',
            'groupPolicy' => 'open',
            'requireMention' => false,
            'replyToMode' => 'off',
        ])
        ->and($patch['session']['dmScope'])->toBe('main');
});

test('config patch routes through the gateway rpc with optimistic locking', function () {
    $slack = AgentSlackConnection::factory()->connected()->create();

    $command = (new ConfigPatchService)->buildSetSlackTokensPatch($slack->fresh());

    expect($command)
        ->toContain('openclaw gateway call config.get --json')
        ->toContain('openclaw gateway call config.patch --json')
        ->toContain('baseHash');
});

test('a hostile slack token cannot break the generated shell command', function () {
    // Crafted to break out of the old node -e single-quoted JS interpolation.
    $slack = AgentSlackConnection::factory()->connected()->create([
        'bot_token' => "xoxb-evil';process.exit(1);//\"'\$(rm -rf /)",
    ]);

    $command = (new ConfigPatchService)->buildSetSlackTokensPatch($slack->fresh());

    // The command must remain syntactically valid bash — the token rides
    // inside a shell-escaped JSON argument, never inside code.
    $file = tempnam(sys_get_temp_dir(), 'patch');
    file_put_contents($file, $command);
    exec('bash -n '.escapeshellarg($file).' 2>&1', $output, $exitCode);
    unlink($file);

    expect($exitCode)->toBe(0, 'bash -n rejected the generated command: '.implode("\n", $output));
});
