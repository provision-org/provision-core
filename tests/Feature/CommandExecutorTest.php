<?php

use App\Contracts\CommandExecutor;
use App\Enums\CloudProvider;
use App\Services\DockerExecutor;
use App\Services\SshService;

it('has SshService implementing CommandExecutor', function () {
    $ssh = new SshService;

    expect($ssh)->toBeInstanceOf(CommandExecutor::class);
});

it('has DockerExecutor implementing CommandExecutor', function () {
    $reflection = new ReflectionClass(DockerExecutor::class);

    expect($reflection->implementsInterface(CommandExecutor::class))->toBeTrue();
});

it('has Docker case in CloudProvider enum', function () {
    expect(CloudProvider::Docker->value)->toBe('docker')
        ->and(CloudProvider::Docker->label())->toBe('Docker')
        ->and(CloudProvider::Docker->defaultRegion())->toBe('local');
});

it('defines all required methods on CommandExecutor interface', function () {
    $reflection = new ReflectionClass(CommandExecutor::class);
    $methods = collect($reflection->getMethods())->pluck('name')->all();

    expect($methods)->toContain('exec')
        ->toContain('execWithRetry')
        ->toContain('writeFile')
        ->toContain('readFile')
        ->toContain('execScript');
});

it('has SshService with all CommandExecutor methods', function () {
    $reflection = new ReflectionClass(SshService::class);

    expect($reflection->implementsInterface(CommandExecutor::class))->toBeTrue();
});

it('has DockerExecutor with all CommandExecutor methods', function () {
    $reflection = new ReflectionClass(DockerExecutor::class);

    expect($reflection->implementsInterface(CommandExecutor::class))->toBeTrue();
});
