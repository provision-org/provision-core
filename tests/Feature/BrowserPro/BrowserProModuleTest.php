<?php

use App\Contracts\Modules\AgentProxyProvider;
use App\Services\ModuleRegistry;
use Provision\BrowserPro\BrowserProModule;
use Provision\BrowserPro\BrowserProServiceProvider;
use Provision\BrowserPro\Services\ProxyConfigService;

beforeEach(function () {
    if (! class_exists(BrowserProModule::class)) {
        $this->markTestSkipped('BrowserPro module not installed');
    }
});

test('service provider registers decodo config', function () {
    expect(config('decodo.proxy_host'))->toBe('gate.decodo.com')
        ->and(config('decodo.proxy_port'))->toBe(7000)
        ->and(config('decodo.default_country'))->toBe('us');
});

test('module is not registered when decodo username is missing', function () {
    config(['decodo.username' => null]);

    $registry = app(ModuleRegistry::class);

    expect($registry->has('browser-pro'))->toBeFalse();
});

test('module is registered when decodo credentials are configured', function () {
    config([
        'decodo.username' => 'test-user',
        'decodo.password' => 'test-pass',
    ]);

    // Re-boot the service provider so it picks up the config
    $provider = new BrowserProServiceProvider(app());
    $provider->boot();

    $registry = app(ModuleRegistry::class);

    expect($registry->has('browser-pro'))->toBeTrue()
        ->and($registry->get('browser-pro'))->toBeInstanceOf(BrowserProModule::class)
        ->and(app(AgentProxyProvider::class))->toBeInstanceOf(BrowserProModule::class);
});

test('browser pro module returns correct key and name', function () {
    $module = new BrowserProModule(new ProxyConfigService);

    expect($module->key())->toBe('browser-pro')
        ->and($module->name())->toBe('browser-pro')
        ->and($module->label())->toBe('Browser Pro');
});

test('proxy config service generates valid pac file', function () {
    $service = new ProxyConfigService;

    $pac = $service->generatePacFile(['.reddit.com', '.linkedin.com']);

    expect($pac)->toContain('FindProxyForURL')
        ->and($pac)->toContain('.reddit.com')
        ->and($pac)->toContain('.linkedin.com')
        ->and($pac)->toContain('SOCKS5 127.0.0.1:1080')
        ->and($pac)->toContain('DIRECT');
});

test('proxy config service generates valid gost config', function () {
    config([
        'decodo.proxy_host' => 'gate.decodo.com',
        'decodo.proxy_port' => 7000,
        'decodo.default_country' => 'us',
        'decodo.session_duration_minutes' => 30,
    ]);

    $service = new ProxyConfigService;

    $yaml = $service->generateGostConfig('testuser', 'testpass', 'agent-123');

    expect($yaml)->toContain('residential-proxy')
        ->and($yaml)->toContain('127.0.0.1:1080')
        ->and($yaml)->toContain('gate.decodo.com:7000')
        ->and($yaml)->toContain('user-testuser-country-us-session-agent-123-sessionduration-30')
        ->and($yaml)->toContain('testpass');
});

test('proxy config service returns default proxy domains', function () {
    $service = new ProxyConfigService;

    $domains = $service->defaultProxyDomains();

    expect($domains)->toContain('.reddit.com')
        ->and($domains)->toContain('.linkedin.com');
});
