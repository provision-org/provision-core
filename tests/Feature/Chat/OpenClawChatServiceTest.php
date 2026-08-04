<?php

use App\Contracts\CommandExecutor;
use App\Enums\ChatMessageRole;
use App\Models\Agent;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\Server;
use App\Models\User;
use App\Services\HarnessManager;
use App\Services\OpenClawChatService;
use Illuminate\Support\Facades\Storage;

function nativeChatFixture(): array
{
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'harness_agent_id' => 'agent-native-test',
    ]);
    $conversation = ChatConversation::factory()->create([
        'agent_id' => $agent->id,
        'user_id' => $user->id,
        'session_key' => 'web:legacy-session',
    ]);
    $message = ChatMessage::factory()->create([
        'chat_conversation_id' => $conversation->id,
        'role' => ChatMessageRole::User,
        'content' => [['type' => 'text', 'text' => 'Hello from Provision']],
        'delivery_status' => 'queued',
    ]);

    return [$conversation, $message, $server];
}

test('native OpenClaw chat sends idempotently and reads the canonical reply from history', function () {
    [$conversation, $message, $server] = nativeChatFixture();
    $executor = Mockery::mock(CommandExecutor::class);

    $executor->shouldReceive('exec')
        ->once()
        ->with(Mockery::on(fn (string $command) => str_contains($command, "'chat.send'")
            && str_contains($command, 'provision-chat:'.$message->id)
            && str_contains($command, 'Hello from Provision')))
        ->andReturn(json_encode(['runId' => 'run-native-1', 'status' => 'started']));

    $executor->shouldReceive('exec')
        ->once()
        ->with(Mockery::on(fn (string $command) => str_contains($command, "'chat.history'")))
        ->andReturn(json_encode([
            'messages' => [
                [
                    'role' => 'user',
                    'content' => 'Hello from Provision',
                    'idempotencyKey' => 'provision-chat:'.$message->id.':user',
                ],
                [
                    'role' => 'assistant',
                    'content' => [
                        ['type' => 'thinking', 'thinking' => 'not user-visible'],
                        ['type' => 'text', 'text' => '[[reply_to_current]] Native reply'],
                    ],
                    '__openclaw' => ['id' => 'reply-native-1'],
                ],
            ],
            'sessionInfo' => ['hasActiveRun' => false],
        ]));

    $manager = Mockery::mock(HarnessManager::class);
    $manager->shouldReceive('resolveExecutor')->once()->withArgs(fn (Server $value) => $value->is($server))->andReturn($executor);

    $result = (new OpenClawChatService($manager))->sendAndWait(
        $conversation,
        $message,
        timeoutSeconds: 1,
        pollIntervalMilliseconds: 0,
    );

    expect($result)->toBe([
        'run_id' => 'run-native-1',
        'upstream_id' => 'openclaw:reply-native-1',
        'content' => [['type' => 'text', 'text' => 'Native reply']],
    ])->and($conversation->fresh()->session_key)
        ->toBe("agent:agent-native-test:dashboard:{$conversation->id}")
        ->and($message->fresh()->delivery_status)->toBe('running')
        ->and($message->fresh()->upstream_run_id)->toBe('run-native-1');
});

test('native OpenClaw chat waits for tool runs and returns the final assistant response', function () {
    [$conversation, $message, $server] = nativeChatFixture();
    $executor = Mockery::mock(CommandExecutor::class);

    $executor->shouldReceive('exec')
        ->once()
        ->with(Mockery::on(fn (string $command) => str_contains($command, "'chat.send'")))
        ->andReturn(json_encode(['runId' => 'run-tool-1', 'status' => 'started']));

    $progress = [
        'role' => 'assistant',
        'content' => [['type' => 'text', 'text' => 'Executing now']],
        '__openclaw' => ['id' => 'reply-progress'],
    ];

    $executor->shouldReceive('exec')
        ->twice()
        ->with(Mockery::on(fn (string $command) => str_contains($command, "'chat.history'")))
        ->andReturn(
            json_encode([
                'messages' => [
                    [
                        'role' => 'user',
                        'idempotencyKey' => 'provision-chat:'.$message->id.':user',
                    ],
                    $progress,
                ],
                'sessionInfo' => [
                    'hasActiveRun' => true,
                    'activeRunIds' => ['run-tool-1'],
                    'status' => 'running',
                ],
            ]),
            json_encode([
                'messages' => [
                    [
                        'role' => 'user',
                        'idempotencyKey' => 'provision-chat:'.$message->id.':user',
                    ],
                    $progress,
                    [
                        'role' => 'toolResult',
                        'content' => [['type' => 'text', 'text' => 'browser complete']],
                    ],
                    [
                        'role' => 'assistant',
                        'content' => [['type' => 'text', 'text' => 'Final browser result']],
                        '__openclaw' => ['id' => 'reply-final'],
                    ],
                ],
                'sessionInfo' => [
                    'hasActiveRun' => false,
                    'activeRunIds' => [],
                    'status' => 'done',
                ],
            ]),
        );

    $manager = Mockery::mock(HarnessManager::class);
    $manager->shouldReceive('resolveExecutor')
        ->once()
        ->withArgs(fn (Server $value) => $value->is($server))
        ->andReturn($executor);

    $result = (new OpenClawChatService($manager))->sendAndWait(
        $conversation,
        $message,
        timeoutSeconds: 1,
        pollIntervalMilliseconds: 0,
    );

    expect($result)->toBe([
        'run_id' => 'run-tool-1',
        'upstream_id' => 'openclaw:reply-final',
        'content' => [['type' => 'text', 'text' => 'Final browser result']],
    ]);
});

test('native OpenClaw reconciliation never claims a later external turn as its reply', function () {
    [$conversation, $message, $server] = nativeChatFixture();
    $executor = Mockery::mock(CommandExecutor::class);

    $executor->shouldReceive('exec')
        ->once()
        ->with(Mockery::on(fn (string $command) => str_contains($command, "'chat.send'")))
        ->andReturn(json_encode(['runId' => 'run-correlated', 'status' => 'started']));
    $executor->shouldReceive('exec')
        ->once()
        ->with(Mockery::on(fn (string $command) => str_contains($command, "'chat.history'")))
        ->andReturn(json_encode([
            'messages' => [
                [
                    'role' => 'user',
                    'idempotencyKey' => 'provision-chat:'.$message->id.':user',
                ],
                [
                    'role' => 'assistant',
                    'content' => 'The correlated response',
                    '__openclaw' => ['id' => 'reply-correlated', 'runId' => 'run-correlated'],
                ],
                [
                    'role' => 'assistant',
                    'content' => 'A response from another run',
                    '__openclaw' => ['id' => 'reply-other-run', 'runId' => 'run-other'],
                ],
                ['role' => 'user', 'content' => 'A later Slack message'],
                [
                    'role' => 'assistant',
                    'content' => 'A later external reply',
                    '__openclaw' => ['id' => 'reply-external'],
                ],
            ],
            'sessionInfo' => ['hasActiveRun' => false, 'status' => 'done'],
        ]));

    $manager = Mockery::mock(HarnessManager::class);
    $manager->shouldReceive('resolveExecutor')
        ->once()
        ->withArgs(fn (Server $value) => $value->is($server))
        ->andReturn($executor);

    $result = (new OpenClawChatService($manager))->sendAndWait(
        $conversation,
        $message,
        timeoutSeconds: 1,
        pollIntervalMilliseconds: 0,
    );

    expect($result['upstream_id'])->toBe('openclaw:reply-correlated')
        ->and($result['content'])->toBe([['type' => 'text', 'text' => 'The correlated response']]);
});

test('native OpenClaw chat imports agent-scoped media directives', function () {
    Storage::fake('local');
    [$conversation, $message, $server] = nativeChatFixture();
    $executor = Mockery::mock(CommandExecutor::class);

    $executor->shouldReceive('exec')
        ->once()
        ->with(Mockery::on(fn (string $command) => str_contains($command, "'chat.send'")))
        ->andReturn(json_encode(['runId' => 'run-media-resolution', 'status' => 'started']));

    $userMessage = [
        'role' => 'user',
        'idempotencyKey' => 'provision-chat:'.$message->id.':user',
    ];
    $rawMediaReply = [
        'role' => 'assistant',
        'content' => [[
            'type' => 'text',
            'text' => "Browser complete\nMEDIA:/root/.openclaw/media/agent-native-test/result.png",
        ]],
        '__openclaw' => ['id' => 'reply-raw-media'],
    ];

    $executor->shouldReceive('exec')
        ->once()
        ->with(Mockery::on(fn (string $command) => str_contains($command, "'chat.history'")))
        ->andReturn(json_encode([
            'messages' => [$userMessage, $rawMediaReply],
            'sessionInfo' => ['hasActiveRun' => false, 'status' => 'done'],
        ]));

    $executor->shouldReceive('exec')
        ->once()
        ->with(Mockery::on(fn (string $command) => str_contains($command, 'node -e')
            && str_contains($command, '/root/.openclaw/media/agent-native-test/result.png')
            && str_contains($command, 'realpathSync')
            && ! str_contains($command, 'Authorization')
            && ! str_contains($command, 'gateway-token-secret')))
        ->andReturn(base64_encode('png-bytes'));

    $manager = Mockery::mock(HarnessManager::class);
    $manager->shouldReceive('resolveExecutor')
        ->once()
        ->withArgs(fn (Server $value) => $value->is($server))
        ->andReturn($executor);

    $result = (new OpenClawChatService($manager))->sendAndWait(
        $conversation,
        $message,
        timeoutSeconds: 1,
        pollIntervalMilliseconds: 0,
    );

    expect($result)->toBe([
        'run_id' => 'run-media-resolution',
        'upstream_id' => 'openclaw:reply-raw-media',
        'content' => [
            ['type' => 'text', 'text' => 'Browser complete'],
            $result['content'][1],
        ],
    ])->and($result['content'][1])->toMatchArray([
        'type' => 'image',
        'disk' => 'local',
        'fileName' => 'result.png',
        'mimeType' => 'image/png',
    ]);

    Storage::disk('local')->assertExists($result['content'][1]['path']);
    expect(Storage::disk('local')->get($result['content'][1]['path']))->toBe('png-bytes');
});

test('native OpenClaw chat rejects media directives from another agent workspace', function () {
    [$conversation, $message, $server] = nativeChatFixture();
    $executor = Mockery::mock(CommandExecutor::class);

    $executor->shouldReceive('exec')
        ->once()
        ->with(Mockery::on(fn (string $command) => str_contains($command, "'chat.send'")))
        ->andReturn(json_encode(['runId' => 'run-cross-agent-media', 'status' => 'started']));

    $executor->shouldReceive('exec')
        ->once()
        ->with(Mockery::on(fn (string $command) => str_contains($command, "'chat.history'")))
        ->andReturn(json_encode([
            'messages' => [
                [
                    'role' => 'user',
                    'idempotencyKey' => 'provision-chat:'.$message->id.':user',
                ],
                [
                    'role' => 'assistant',
                    'content' => [[
                        'type' => 'text',
                        'text' => "Not allowed\nMEDIA:/root/.openclaw/media/another-agent/result.png",
                    ]],
                    '__openclaw' => ['id' => 'reply-cross-agent-media'],
                ],
            ],
            'sessionInfo' => ['hasActiveRun' => false, 'status' => 'done'],
        ]));

    $manager = Mockery::mock(HarnessManager::class);
    $manager->shouldReceive('resolveExecutor')
        ->once()
        ->withArgs(fn (Server $value) => $value->is($server))
        ->andReturn($executor);

    expect(fn () => (new OpenClawChatService($manager))->sendAndWait(
        $conversation,
        $message,
        timeoutSeconds: 1,
        pollIntervalMilliseconds: 0,
    ))->toThrow(RuntimeException::class, 'The agent media could not be retrieved.');
});

test('native OpenClaw chat imports authenticated gateway media URLs', function () {
    Storage::fake('local');
    [$conversation, $message, $server] = nativeChatFixture();
    $executor = Mockery::mock(CommandExecutor::class);
    $sessionKey = "agent:agent-native-test:dashboard:{$conversation->id}";
    $mediaUrl = '/api/chat/media/outgoing/'.rawurlencode($sessionKey)
        .'/e00fb11c-ae44-4893-8c87-1d47880cc038/full';

    $executor->shouldReceive('exec')
        ->once()
        ->with(Mockery::on(fn (string $command) => str_contains($command, "'chat.send'")))
        ->andReturn(json_encode(['runId' => 'run-gateway-media', 'status' => 'started']));

    $executor->shouldReceive('exec')
        ->once()
        ->with(Mockery::on(fn (string $command) => str_contains($command, "'chat.history'")))
        ->andReturn(json_encode([
            'messages' => [
                [
                    'role' => 'user',
                    'idempotencyKey' => 'provision-chat:'.$message->id.':user',
                ],
                [
                    'role' => 'assistant',
                    'content' => [
                        ['type' => 'text', 'text' => 'Browser complete'],
                        [
                            'type' => 'image',
                            'url' => $mediaUrl,
                            'alt' => 'result.png',
                            'mimeType' => 'image/png',
                        ],
                    ],
                    '__openclaw' => ['id' => 'reply-gateway-media'],
                ],
            ],
            'sessionInfo' => ['hasActiveRun' => false, 'status' => 'done'],
        ]));

    $executor->shouldReceive('exec')
        ->once()
        ->with(Mockery::on(fn (string $command) => str_contains($command, 'node -e')
            && str_contains($command, '/root/.openclaw/openclaw.json')
            && str_contains($command, '/api/chat/media/')
            && str_contains($command, 'Authorization')
            && ! str_contains($command, 'gateway-token-secret')))
        ->andReturn(base64_encode('gateway-png-bytes'));

    $manager = Mockery::mock(HarnessManager::class);
    $manager->shouldReceive('resolveExecutor')
        ->once()
        ->withArgs(fn (Server $value) => $value->is($server))
        ->andReturn($executor);

    $result = (new OpenClawChatService($manager))->sendAndWait(
        $conversation,
        $message,
        timeoutSeconds: 1,
        pollIntervalMilliseconds: 0,
    );

    expect($result['upstream_id'])->toBe('openclaw:reply-gateway-media')
        ->and($result['content'])->toHaveCount(2)
        ->and($result['content'][1])->toMatchArray([
            'type' => 'image',
            'disk' => 'local',
            'fileName' => 'result.png',
            'mimeType' => 'image/png',
        ]);

    Storage::disk('local')->assertExists($result['content'][1]['path']);
    expect(Storage::disk('local')->get($result['content'][1]['path']))->toBe('gateway-png-bytes');
});

test('native OpenClaw chat rejects unscoped gateway media URLs', function (string $invalidMediaUrl) {
    [$conversation, $message, $server] = nativeChatFixture();
    $executor = Mockery::mock(CommandExecutor::class);
    $mediaUrl = str_replace('{conversation}', $conversation->id, $invalidMediaUrl);

    $executor->shouldReceive('exec')
        ->once()
        ->with(Mockery::on(fn (string $command) => str_contains($command, "'chat.send'")))
        ->andReturn(json_encode(['runId' => 'run-invalid-gateway-media', 'status' => 'started']));

    $executor->shouldReceive('exec')
        ->once()
        ->with(Mockery::on(fn (string $command) => str_contains($command, "'chat.history'")))
        ->andReturn(json_encode([
            'messages' => [
                [
                    'role' => 'user',
                    'idempotencyKey' => 'provision-chat:'.$message->id.':user',
                ],
                [
                    'role' => 'assistant',
                    'content' => [
                        ['type' => 'text', 'text' => 'Not allowed'],
                        [
                            'type' => 'image',
                            'url' => $mediaUrl,
                            'alt' => 'result.png',
                            'mimeType' => 'image/png',
                        ],
                    ],
                    '__openclaw' => ['id' => 'reply-invalid-gateway-media'],
                ],
            ],
            'sessionInfo' => ['hasActiveRun' => false, 'status' => 'done'],
        ]));

    $manager = Mockery::mock(HarnessManager::class);
    $manager->shouldReceive('resolveExecutor')
        ->once()
        ->withArgs(fn (Server $value) => $value->is($server))
        ->andReturn($executor);

    expect(fn () => (new OpenClawChatService($manager))->sendAndWait(
        $conversation,
        $message,
        timeoutSeconds: 1,
        pollIntervalMilliseconds: 0,
    ))->toThrow(RuntimeException::class, 'The agent media could not be retrieved.');
})->with([
    'another conversation session' => [
        '/api/chat/media/outgoing/'
            .'agent%3Aagent-native-test%3Adashboard%3A01j00000000000000000000000'
            .'/e00fb11c-ae44-4893-8c87-1d47880cc038/full',
    ],
    'path traversal' => [
        '/api/chat/media/outgoing/'
            .'agent%3Aagent-native-test%3Adashboard%3A{conversation}'
            .'/../../owner/full',
    ],
]);

test('native OpenClaw chat stages every file in the agent workspace', function () {
    Storage::fake('local');
    [$conversation, $message, $server] = nativeChatFixture();
    Storage::disk('local')->put('chat-attachments/notes.txt', 'important notes');
    $message->update([
        'content' => [[
            'type' => 'file',
            'path' => 'chat-attachments/notes.txt',
            'fileName' => 'Quarterly Notes.txt',
            'mimeType' => 'text/plain',
        ]],
    ]);

    $executor = Mockery::mock(CommandExecutor::class);
    $executor->shouldReceive('exec')->once()->with(Mockery::on(fn (string $command) => str_contains($command, 'install -d -m 0700')))->andReturn('');
    $executor->shouldReceive('writeFile')
        ->once()
        ->with(Mockery::on(fn (string $path) => str_ends_with($path, '/01-quarterly-notes.txt')), 'important notes');
    $executor->shouldReceive('exec')->once()->with(Mockery::on(fn (string $command) => str_contains($command, 'chmod 0600')))->andReturn('');
    $executor->shouldReceive('exec')
        ->once()
        ->with(Mockery::on(fn (string $command) => str_contains($command, "'chat.send'")
            && str_contains($command, 'Quarterly Notes.txt')
            && str_contains($command, '01-quarterly-notes.txt')))
        ->andReturn(json_encode(['runId' => 'run-file', 'status' => 'started']));
    $executor->shouldReceive('exec')
        ->once()
        ->with(Mockery::on(fn (string $command) => str_contains($command, "'chat.history'")))
        ->andReturn(json_encode([
            'messages' => [
                ['role' => 'user', 'idempotencyKey' => 'provision-chat:'.$message->id.':user'],
                ['role' => 'assistant', 'content' => 'File received', '__openclaw' => ['id' => 'file-reply']],
            ],
        ]));
    $executor->shouldReceive('exec')
        ->once()
        ->with(Mockery::on(fn (string $command) => str_contains($command, 'rm -rf --')
            && str_contains($command, 'provision-chat-attachments')))
        ->andReturn('');

    $manager = Mockery::mock(HarnessManager::class);
    $manager->shouldReceive('resolveExecutor')->once()->withArgs(fn (Server $value) => $value->is($server))->andReturn($executor);

    $result = (new OpenClawChatService($manager))->sendAndWait(
        $conversation,
        $message,
        timeoutSeconds: 1,
        pollIntervalMilliseconds: 0,
    );

    expect($result['content'])->toBe([['type' => 'text', 'text' => 'File received']]);
});

test('native OpenClaw chat keeps large attachments out of the gateway command argument', function () {
    Storage::fake('local');
    [$conversation, $message, $server] = nativeChatFixture();
    $contents = str_repeat('large-attachment-', 10_240);
    Storage::disk('local')->put('chat-attachments/large.txt', $contents);
    $message->update([
        'content' => [[
            'type' => 'file',
            'path' => 'chat-attachments/large.txt',
            'fileName' => 'Large Attachment.txt',
            'mimeType' => 'text/plain',
        ]],
    ]);

    $executor = Mockery::mock(CommandExecutor::class);
    $executor->shouldReceive('exec')->once()->with(Mockery::on(fn (string $command) => str_contains($command, 'install -d -m 0700')))->andReturn('');
    $executor->shouldReceive('writeFile')
        ->once()
        ->with(Mockery::on(fn (string $path) => str_ends_with($path, '/01-large-attachment.txt')), $contents);
    $executor->shouldReceive('exec')->once()->with(Mockery::on(fn (string $command) => str_contains($command, 'chmod 0600')))->andReturn('');
    $executor->shouldReceive('exec')
        ->once()
        ->with(Mockery::on(fn (string $command) => str_contains($command, "'chat.send'")
            && str_contains($command, '01-large-attachment.txt')
            && ! str_contains($command, '"attachments"')
            && strlen($command) < 100_000))
        ->andReturn(json_encode(['runId' => 'run-large-file', 'status' => 'started']));
    $executor->shouldReceive('exec')
        ->once()
        ->with(Mockery::on(fn (string $command) => str_contains($command, "'chat.history'")))
        ->andReturn(json_encode([
            'messages' => [
                ['role' => 'user', 'idempotencyKey' => 'provision-chat:'.$message->id.':user'],
                ['role' => 'assistant', 'content' => 'Large file received', '__openclaw' => ['id' => 'large-file-reply']],
            ],
        ]));
    $executor->shouldReceive('exec')
        ->once()
        ->with(Mockery::on(fn (string $command) => str_contains($command, 'rm -rf --')
            && str_contains($command, 'provision-chat-attachments')))
        ->andReturn('');

    $manager = Mockery::mock(HarnessManager::class);
    $manager->shouldReceive('resolveExecutor')->once()->withArgs(fn (Server $value) => $value->is($server))->andReturn($executor);

    $result = (new OpenClawChatService($manager))->sendAndWait(
        $conversation,
        $message,
        timeoutSeconds: 1,
        pollIntervalMilliseconds: 0,
    );

    expect($result['content'])->toBe([['type' => 'text', 'text' => 'Large file received']]);
});

test('native OpenClaw chat preserves assistant media in the durable transcript', function () {
    Storage::fake('local');
    [$conversation, $message, $server] = nativeChatFixture();
    $executor = Mockery::mock(CommandExecutor::class);
    $executor->shouldReceive('exec')
        ->once()
        ->with(Mockery::on(fn (string $command) => str_contains($command, "'chat.send'")))
        ->andReturn(json_encode(['runId' => 'run-media', 'status' => 'started']));
    $executor->shouldReceive('exec')
        ->once()
        ->with(Mockery::on(fn (string $command) => str_contains($command, "'chat.history'")))
        ->andReturn(json_encode([
            'messages' => [
                ['role' => 'user', 'idempotencyKey' => 'provision-chat:'.$message->id.':user'],
                [
                    'role' => 'assistant',
                    'content' => [
                        ['type' => 'text', 'text' => 'Here is the image'],
                        [
                            'type' => 'image',
                            'source' => [
                                'media_type' => 'image/png',
                                'data' => base64_encode('png-bytes'),
                            ],
                            'fileName' => 'result.png',
                        ],
                    ],
                    '__openclaw' => ['id' => 'media-reply'],
                ],
            ],
        ]));

    $manager = Mockery::mock(HarnessManager::class);
    $manager->shouldReceive('resolveExecutor')->once()->withArgs(fn (Server $value) => $value->is($server))->andReturn($executor);

    $result = (new OpenClawChatService($manager))->sendAndWait(
        $conversation,
        $message,
        timeoutSeconds: 1,
        pollIntervalMilliseconds: 0,
    );

    expect($result['content'])->toHaveCount(2)
        ->and($result['content'][0])->toBe(['type' => 'text', 'text' => 'Here is the image'])
        ->and($result['content'][1])->toMatchArray([
            'type' => 'image',
            'disk' => 'local',
            'fileName' => 'result.png',
            'mimeType' => 'image/png',
        ]);
    Storage::disk('local')->assertExists($result['content'][1]['path']);
});

test('native OpenClaw chat aborts the active run when cancellation is requested', function () {
    [$conversation, $message, $server] = nativeChatFixture();
    $executor = Mockery::mock(CommandExecutor::class);
    $executor->shouldReceive('exec')
        ->once()
        ->with(Mockery::on(fn (string $command) => str_contains($command, "'chat.send'")))
        ->andReturnUsing(function () use ($message): string {
            $message->update([
                'delivery_status' => 'aborted',
                'delivery_error' => 'Response stopped.',
            ]);

            return json_encode(['runId' => 'run-cancel', 'status' => 'started']);
        });
    $executor->shouldReceive('exec')
        ->once()
        ->with(Mockery::on(fn (string $command) => str_contains($command, "'chat.abort'")
            && str_contains($command, 'run-cancel')))
        ->andReturn(json_encode(['ok' => true, 'aborted' => true]));

    $manager = Mockery::mock(HarnessManager::class);
    $manager->shouldReceive('resolveExecutor')->once()->withArgs(fn (Server $value) => $value->is($server))->andReturn($executor);

    expect(fn () => (new OpenClawChatService($manager))->sendAndWait(
        $conversation,
        $message,
        cancelled: function () use ($message): bool {
            $message->refresh();

            return $message->delivery_status === 'aborted';
        },
        timeoutSeconds: 1,
        pollIntervalMilliseconds: 0,
    ))->toThrow(RuntimeException::class, 'The response was stopped.');

    expect($message->fresh()->delivery_status)->toBe('aborted')
        ->and($message->fresh()->upstream_run_id)->toBeNull();
});

test('native OpenClaw chat does not send when cancellation is already durable', function () {
    [$conversation, $message, $server] = nativeChatFixture();
    $message->update(['delivery_status' => 'aborted']);
    $executor = Mockery::mock(CommandExecutor::class);
    $executor->shouldNotReceive('exec');

    $manager = Mockery::mock(HarnessManager::class);
    $manager->shouldReceive('resolveExecutor')->once()->withArgs(fn (Server $value) => $value->is($server))->andReturn($executor);

    expect(fn () => (new OpenClawChatService($manager))->sendAndWait(
        $conversation,
        $message,
        cancelled: function () use ($message): bool {
            $message->refresh();

            return $message->delivery_status === 'aborted';
        },
        timeoutSeconds: 1,
        pollIntervalMilliseconds: 0,
    ))->toThrow(RuntimeException::class, 'The response was stopped.');
});
