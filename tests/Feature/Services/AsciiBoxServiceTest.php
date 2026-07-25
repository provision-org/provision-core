<?php

use App\Services\AsciiBoxService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

afterEach(function () {
    Sleep::fake(false);
});

it('creates an isolated box with a provisioning safety ttl', function () {
    Http::fake([
        'ascii.test/api/boxes' => Http::response([
            'ok' => true,
            'type' => 'box.created',
            'box' => [
                'id' => 'bx_23456789',
                'state' => 'provisioning',
                'ip' => null,
            ],
        ], 202),
    ]);

    config()->set('cloud.ascii.base_url', 'https://ascii.test/api');

    $box = (new AsciiBoxService('test-token'))->createBox([
        'PROVISION_TEAM_ID' => 'team-123',
    ]);

    expect($box['id'])->toBe('bx_23456789');

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://ascii.test/api/boxes'
            && $request->hasHeader('Authorization', 'Bearer test-token')
            && $request['ttlSeconds'] === 3600
            && $request['noEnv'] === true
            && $request['env'] === ['PROVISION_TEAM_ID' => 'team-123'];
    });
});

it('disables auto-stop after provisioning completes', function () {
    config()->set('cloud.ascii.base_url', 'https://ascii.test/api');

    Http::fake([
        'ascii.test/api/boxes/bx_23456789' => Http::response([
            'ok' => true,
            'box' => ['id' => 'bx_23456789', 'state' => 'idle'],
        ]),
    ]);

    (new AsciiBoxService('test-token'))->disableAutoStop('bx_23456789');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
        && $request->url() === 'https://ascii.test/api/boxes/bx_23456789'
        && $request['ttlSeconds'] === null);
});

it('waits for a ready box with an ipv4 address', function () {
    Sleep::fake();
    config()->set('cloud.ascii.base_url', 'https://ascii.test/api');

    Http::fakeSequence()
        ->push(['box' => ['id' => 'bx_23456789', 'state' => 'provisioning', 'ip' => null]])
        ->push(['box' => ['id' => 'bx_23456789', 'state' => 'idle', 'ip' => '203.0.113.10']]);

    $box = (new AsciiBoxService('test-token'))->waitUntilReady('bx_23456789', attempts: 2);

    expect($box['state'])->toBe('idle')
        ->and($box['ip'])->toBe('203.0.113.10');

    Sleep::assertSleptTimes(1);
});

it('mints a fresh authenticated desktop url in the default streaming mode', function () {
    config()->set('cloud.ascii.base_url', 'https://ascii.test/api');

    Http::fake([
        'ascii.test/api/boxes/bx_23456789/desktop*' => Http::response([
            'ok' => true,
            'type' => 'desktop.url',
            'success' => true,
            'desktopUrl' => 'https://desktop.ascii.test/stream.html?token=secret-session',
            'mode' => 'moonlight',
        ]),
    ]);

    $desktopUrl = (new AsciiBoxService('test-token'))->getDesktopUrl('bx_23456789');

    expect($desktopUrl)->toBe('https://desktop.ascii.test/stream.html?token=secret-session');

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'POST'
            && $request->url() === 'https://ascii.test/api/boxes/bx_23456789/desktop?theme=light'
            && $request->hasHeader('Authorization', 'Bearer test-token')
            && ! str_contains($request->url(), 'vnc=1')
            && ! str_contains($request->body(), 'publicAccess');
    });
});

it('rejects an unavailable or insecure desktop url without exposing it', function (array $response) {
    config()->set('cloud.ascii.base_url', 'https://ascii.test/api');

    Http::fake([
        'ascii.test/api/boxes/bx_23456789/desktop*' => Http::response($response),
    ]);

    expect(fn () => (new AsciiBoxService('test-token'))->getDesktopUrl('bx_23456789'))
        ->toThrow(RuntimeException::class, 'The ASCII Box desktop is not available yet.');
})->with([
    'still provisioning' => [[
        'ok' => true,
        'type' => 'desktop.provisioning',
        'provisioning' => true,
    ]],
    'non-https url' => [[
        'ok' => true,
        'type' => 'desktop.url',
        'desktopUrl' => 'http://desktop.ascii.test/stream.html?token=must-not-leak',
    ]],
]);

it('rejects an invalid box id before making a request', function () {
    Http::preventStrayRequests();
    config()->set('cloud.ascii.base_url', 'https://ascii.test/api');

    expect(fn () => (new AsciiBoxService('test-token'))->getDesktopUrl('../boxes'))
        ->toThrow(RuntimeException::class, 'The ASCII Box id is invalid.');

    Http::assertNothingSent();
});

it('authorizes only the public key and prepares root ssh access', function () {
    config()->set('cloud.ascii.base_url', 'https://ascii.test/api');

    Http::fake([
        'ascii.test/api/boxes/bx_23456789/sshkey' => Http::response([
            'ok' => true,
            'success' => true,
            'machineIp' => '203.0.113.10',
            'sshUser' => 'user',
        ]),
        'ascii.test/api/boxes/bx_23456789/commands' => Http::response([
            'ok' => true,
            'success' => true,
            'exitCode' => 0,
            'stdout' => '',
            'stderr' => '',
            'timedOut' => false,
        ]),
    ]);

    (new AsciiBoxService('test-token'))->configureProvisionSsh(
        'bx_23456789',
        'ssh-ed25519 AAAATEST provision',
    );

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://ascii.test/api/boxes/bx_23456789/sshkey'
        && $request['key'] === 'ssh-ed25519 AAAATEST provision');

    Http::assertSent(function (Request $request): bool {
        if ($request->url() !== 'https://ascii.test/api/boxes/bx_23456789/commands') {
            return false;
        }

        preg_match("/printf '%s' '([^']+)' \\| base64 -d \\| sudo bash/", $request['command'], $matches);
        $script = isset($matches[1]) ? base64_decode($matches[1], true) : false;

        return is_string($script)
            && str_contains($script, '/root/.ssh/authorized_keys')
            && str_contains($script, 'grep -qxF')
            && str_contains($script, 'PermitRootLogin prohibit-password')
            && str_contains($script, '/usr/sbin/sshd -t')
            && $request['timeoutSeconds'] === 60;
    });
});

it('launches bootstrap in the background with an idempotency marker', function () {
    config()->set('cloud.ascii.base_url', 'https://ascii.test/api');

    Http::fake([
        'ascii.test/api/boxes/bx_23456789/commands' => Http::response([
            'ok' => true,
            'success' => true,
            'exitCode' => 0,
            'stdout' => '',
            'stderr' => '',
            'timedOut' => false,
        ]),
    ]);

    (new AsciiBoxService('test-token'))->startBootstrap('bx_23456789', "#!/bin/bash\necho ready\n");

    Http::assertSent(function (Request $request): bool {
        if ($request->url() !== 'https://ascii.test/api/boxes/bx_23456789/commands') {
            return false;
        }

        preg_match("/printf '%s' '([^']+)' \\| base64 -d \\| sudo bash/", $request['command'], $matches);
        $launcher = isset($matches[1]) ? base64_decode($matches[1], true) : false;

        return is_string($launcher)
            && str_contains($launcher, '/var/lib/provision/bootstrap-complete')
            && str_contains($launcher, 'nohup flock')
            && str_contains($launcher, base64_encode("#!/bin/bash\necho ready\n"));
    });
});

it('archives a box and waits for the snapshot to finish', function () {
    Sleep::fake();
    config()->set('cloud.ascii.base_url', 'https://ascii.test/api');

    $getCalls = 0;
    Http::fake(function (Request $request) use (&$getCalls) {
        if ($request->method() === 'POST') {
            return Http::response([
                'ok' => true,
                'status' => 'archiving',
                'box' => ['id' => 'bx_23456789', 'state' => 'archiving'],
            ], 202);
        }

        $getCalls++;

        return Http::response([
            'box' => [
                'id' => 'bx_23456789',
                'state' => $getCalls === 1 ? 'idle' : ($getCalls === 2 ? 'archiving' : 'archived'),
            ],
        ]);
    });

    (new AsciiBoxService('test-token'))->archiveBox('bx_23456789', attempts: 2);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://ascii.test/api/boxes/bx_23456789/stop');
    Sleep::assertSleptTimes(1);
});

it('treats an already missing box as archived', function () {
    config()->set('cloud.ascii.base_url', 'https://ascii.test/api');

    Http::fake([
        'ascii.test/api/boxes/bx_missing' => Http::response([
            'ok' => false,
            'code' => 'not_found',
        ], 404),
    ]);

    (new AsciiBoxService('test-token'))->archiveBox('bx_missing');

    Http::assertSentCount(1);
});

it('fails fast when the api key is absent', function () {
    config()->set('cloud.ascii.api_token');

    expect(fn () => new AsciiBoxService)->toThrow(
        RuntimeException::class,
        'ASCII Box API key is not configured.',
    );
});
