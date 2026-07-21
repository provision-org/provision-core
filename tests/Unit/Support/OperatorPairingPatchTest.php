<?php

use App\Support\OperatorPairingPatch;
use Symfony\Component\Process\Process;

function makeOperatorPairingHome(): string
{
    $home = sys_get_temp_dir().'/provision openclaw '.bin2hex(random_bytes(8));

    mkdir($home.'/identity', 0777, true);
    mkdir($home.'/devices', 0777, true);

    return $home;
}

/**
 * @param  array<string, mixed>  $value
 */
function writeOperatorPairingJson(string $path, array $value): void
{
    file_put_contents($path, json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
}

/**
 * @return array<string, mixed>
 */
function readOperatorPairingJson(string $path): array
{
    return json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
}

function operatorPairingFileMode(string $path): int
{
    clearstatcache(true, $path);

    return fileperms($path) & 0777;
}

/** @return list<string> */
function operatorPairingBackupFiles(string $home): array
{
    $backups = array_merge(
        glob($home.'/identity/device-auth.json.bak.*') ?: [],
        glob($home.'/devices/paired.json.bak.*') ?: [],
        glob($home.'/devices/pending.json.bak.*') ?: [],
    );
    sort($backups);

    return $backups;
}

/** @return list<string> */
function operatorPairingTemporaryFiles(string $home): array
{
    return array_merge(
        glob($home.'/identity/device-auth.json.provision.*') ?: [],
        glob($home.'/devices/paired.json.provision.*') ?: [],
        glob($home.'/devices/pending.json.provision.*') ?: [],
    );
}

function removeOperatorPairingHome(string $home): void
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($home, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }

    rmdir($home);
}

function runOperatorPairingPatch(string $home): void
{
    Process::fromShellCommandline(OperatorPairingPatch::buildScript($home))
        ->setTimeout(10)
        ->mustRun();
}

it('upgrades only the local device and preserves unrelated paired and pending devices', function () {
    $home = makeOperatorPairingHome();

    $localDevice = [
        'deviceId' => 'local-device',
        'displayName' => 'Provision host',
        'scopes' => ['operator.read', 'operator.custom'],
        'approvedScopes' => ['operator.read'],
        'tokens' => [
            'operator' => [
                'token' => 'local-secret',
                'scopes' => ['operator.read', 'operator.custom'],
            ],
        ],
    ];
    $mobileDevice = [
        'deviceId' => 'mobile-device',
        'displayName' => 'Provision iPhone',
        'scopes' => ['operator.read', 'operator.write'],
        'approvedScopes' => ['operator.read', 'operator.write'],
        'tokens' => [
            'operator' => [
                'token' => 'mobile-secret',
                'scopes' => ['operator.read', 'operator.write'],
            ],
        ],
    ];
    $pending = [
        'local-request' => ['deviceId' => 'local-device', 'scopes' => ['operator.admin']],
        'mobile-request' => ['deviceId' => 'mobile-device', 'scopes' => ['operator.write']],
        'other-request' => ['deviceId' => 'other-device', 'scopes' => ['operator.read']],
    ];

    writeOperatorPairingJson($home.'/identity/device.json', [
        'version' => 1,
        'deviceId' => 'local-device',
        'privateKeyPem' => 'private-key-must-not-change',
    ]);
    writeOperatorPairingJson($home.'/identity/device-auth.json', [
        'version' => 1,
        'deviceId' => 'local-device',
        'tokens' => [
            'operator' => [
                'token' => 'local-auth-secret',
                'scopes' => ['operator.read', 'operator.custom'],
            ],
        ],
    ]);
    writeOperatorPairingJson($home.'/devices/paired.json', [
        'local-device' => $localDevice,
        'mobile-device' => $mobileDevice,
    ]);
    writeOperatorPairingJson($home.'/devices/pending.json', $pending);

    foreach ([
        $home.'/identity/device.json',
        $home.'/identity/device-auth.json',
        $home.'/devices/paired.json',
        $home.'/devices/pending.json',
    ] as $path) {
        chmod($path, 0644);
    }
    $expectedOwner = fileowner($home.'/identity/device.json');
    $expectedGroup = filegroup($home.'/identity/device.json');

    try {
        runOperatorPairingPatch($home);

        $paired = readOperatorPairingJson($home.'/devices/paired.json');
        $deviceAuth = readOperatorPairingJson($home.'/identity/device-auth.json');
        $identity = readOperatorPairingJson($home.'/identity/device.json');
        $remainingPending = readOperatorPairingJson($home.'/devices/pending.json');
        $expectedLocalScopes = [...OperatorPairingPatch::SCOPES, 'operator.custom'];

        expect($paired['local-device']['scopes'])
            ->toEqualCanonicalizing($expectedLocalScopes)
            ->and($paired['local-device']['approvedScopes'])
            ->toEqualCanonicalizing(OperatorPairingPatch::SCOPES)
            ->and($paired['local-device']['tokens']['operator']['scopes'])
            ->toEqualCanonicalizing($expectedLocalScopes)
            ->and($paired['local-device']['tokens']['operator']['token'])->toBe('local-secret')
            ->and($paired['mobile-device'])->toBe($mobileDevice)
            ->and($deviceAuth['tokens']['operator']['scopes'])
            ->toEqualCanonicalizing($expectedLocalScopes)
            ->and($deviceAuth['tokens']['operator']['token'])->toBe('local-auth-secret')
            ->and($identity['privateKeyPem'])->toBe('private-key-must-not-change')
            ->and($remainingPending)->toBe([
                'mobile-request' => $pending['mobile-request'],
                'other-request' => $pending['other-request'],
            ]);

        $secureFiles = [
            $home.'/identity/device-auth.json',
            $home.'/devices/paired.json',
            $home.'/devices/pending.json',
        ];
        $backupsAfterFirstRun = operatorPairingBackupFiles($home);

        expect($backupsAfterFirstRun)->toHaveCount(3)
            ->and(operatorPairingTemporaryFiles($home))->toBeEmpty();

        foreach ([...$secureFiles, ...$backupsAfterFirstRun] as $path) {
            expect(operatorPairingFileMode($path))->toBe(0600)
                ->and(fileowner($path))->toBe($expectedOwner)
                ->and(filegroup($path))->toBe($expectedGroup);
        }

        $firstRun = [
            'paired' => $paired,
            'deviceAuth' => $deviceAuth,
            'pending' => $remainingPending,
        ];

        runOperatorPairingPatch($home);

        expect([
            'paired' => readOperatorPairingJson($home.'/devices/paired.json'),
            'deviceAuth' => readOperatorPairingJson($home.'/identity/device-auth.json'),
            'pending' => readOperatorPairingJson($home.'/devices/pending.json'),
        ])->toBe($firstRun)
            ->and(operatorPairingBackupFiles($home))->toBe($backupsAfterFirstRun)
            ->and(operatorPairingTemporaryFiles($home))->toBeEmpty();
    } finally {
        removeOperatorPairingHome($home);
    }
});

it('does not modify paired or pending devices without a local device identity', function () {
    $home = makeOperatorPairingHome();
    $paired = [
        'mobile-device' => [
            'deviceId' => 'mobile-device',
            'scopes' => ['operator.read'],
            'approvedScopes' => ['operator.read'],
        ],
    ];
    $pending = [
        'mobile-request' => ['deviceId' => 'mobile-device', 'scopes' => ['operator.write']],
    ];

    writeOperatorPairingJson($home.'/devices/paired.json', $paired);
    writeOperatorPairingJson($home.'/devices/pending.json', $pending);

    try {
        runOperatorPairingPatch($home);

        expect(readOperatorPairingJson($home.'/devices/paired.json'))->toBe($paired)
            ->and(readOperatorPairingJson($home.'/devices/pending.json'))->toBe($pending);
    } finally {
        removeOperatorPairingHome($home);
    }
});

it('serializes concurrent patches and leaves one secure atomic replacement per file', function () {
    $home = makeOperatorPairingHome();
    $mobileDevice = [
        'deviceId' => 'mobile-device',
        'scopes' => ['operator.read'],
        'approvedScopes' => ['operator.read'],
        'tokens' => [
            'operator' => [
                'token' => 'mobile-secret',
                'scopes' => ['operator.read'],
            ],
        ],
    ];

    writeOperatorPairingJson($home.'/identity/device.json', [
        'deviceId' => 'local-device',
        'privateKeyPem' => 'private-key-must-not-change',
    ]);
    writeOperatorPairingJson($home.'/identity/device-auth.json', [
        'deviceId' => 'local-device',
        'tokens' => [
            'operator' => [
                'token' => 'local-auth-secret',
                'scopes' => ['operator.read'],
            ],
        ],
    ]);
    writeOperatorPairingJson($home.'/devices/paired.json', [
        'local-device' => [
            'deviceId' => 'local-device',
            'scopes' => ['operator.read'],
            'approvedScopes' => ['operator.read'],
            'tokens' => [
                'operator' => [
                    'token' => 'local-secret',
                    'scopes' => ['operator.read'],
                ],
            ],
        ],
        'mobile-device' => $mobileDevice,
    ]);
    writeOperatorPairingJson($home.'/devices/pending.json', [
        'local-request' => ['deviceId' => 'local-device', 'scopes' => ['operator.admin']],
        'mobile-request' => ['deviceId' => 'mobile-device', 'scopes' => ['operator.write']],
    ]);

    $script = OperatorPairingPatch::buildScript($home);
    $processes = [];

    try {
        foreach (range(1, 4) as $_) {
            $process = Process::fromShellCommandline($script)->setTimeout(15);
            $process->start();
            $processes[] = $process;
        }

        foreach ($processes as $process) {
            expect($process->wait())->toBe(0);
        }

        $paired = readOperatorPairingJson($home.'/devices/paired.json');
        $pending = readOperatorPairingJson($home.'/devices/pending.json');
        $backups = operatorPairingBackupFiles($home);

        expect($script)->toContain('flock -x 9')
            ->toContain('mktemp "${provision_source_file}.provision.XXXXXX"')
            ->toContain('chmod 0600')
            ->and($paired['mobile-device'])->toBe($mobileDevice)
            ->and($pending)->toBe([
                'mobile-request' => ['deviceId' => 'mobile-device', 'scopes' => ['operator.write']],
            ])
            ->and($backups)->toHaveCount(3)
            ->and(operatorPairingTemporaryFiles($home))->toBeEmpty()
            ->and(is_dir($home.'/.provision-operator-pairing.lock.d'))->toBeFalse();

        foreach ([
            $home.'/identity/device-auth.json',
            $home.'/devices/paired.json',
            $home.'/devices/pending.json',
            ...$backups,
        ] as $path) {
            expect(operatorPairingFileMode($path))->toBe(0600);
        }
    } finally {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop();
            }
        }

        removeOperatorPairingHome($home);
    }
});
