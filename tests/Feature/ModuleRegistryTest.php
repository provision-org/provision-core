<?php

use App\Contracts\Modules\ModuleContract;
use App\Models\Agent;
use App\Services\ModuleRegistry;
use Illuminate\Http\Request;

beforeEach(function () {
    $this->registry = new ModuleRegistry;
});

function createFakeModule(string $name = 'test-module', array $capabilities = ['email']): ModuleContract
{
    return new class($name, $capabilities) implements ModuleContract
    {
        public function __construct(
            private string $moduleName,
            private array $moduleCapabilities,
        ) {}

        public function name(): string
        {
            return $this->moduleName;
        }

        public function label(): string
        {
            return ucfirst($this->moduleName);
        }

        public function version(): string
        {
            return '1.0.0';
        }

        public function capabilities(): array
        {
            return $this->moduleCapabilities;
        }

        public function installScriptSections(Agent $agent): array
        {
            return [['name' => $this->moduleName, 'script' => 'echo hello']];
        }

        public function cleanupAgent(Agent $agent): void {}

        public function sharedProps(Request $request): array
        {
            return [$this->moduleName.'_enabled' => true];
        }
    };
}

test('registers and retrieves a module', function () {
    $module = createFakeModule();

    $this->registry->register($module);

    expect($this->registry->has('test-module'))->toBeTrue()
        ->and($this->registry->get('test-module'))->toBe($module);
});

test('returns null for unregistered module', function () {
    expect($this->registry->has('nonexistent'))->toBeFalse()
        ->and($this->registry->get('nonexistent'))->toBeNull();
});

test('checks capability across modules', function () {
    $this->registry->register(createFakeModule('email-mod', ['email']));
    $this->registry->register(createFakeModule('browser-mod', ['browser']));

    expect($this->registry->hasCapability('email'))->toBeTrue()
        ->and($this->registry->hasCapability('browser'))->toBeTrue()
        ->and($this->registry->hasCapability('billing'))->toBeFalse();
});

test('finds module for a capability', function () {
    $module = createFakeModule('email-mod', ['email']);
    $this->registry->register($module);

    expect($this->registry->forCapability('email'))->toBe($module)
        ->and($this->registry->forCapability('nonexistent'))->toBeNull();
});

test('collects install script sections from all modules', function () {
    $this->registry->register(createFakeModule('mod-a', ['a']));
    $this->registry->register(createFakeModule('mod-b', ['b']));

    $agent = Agent::factory()->make();
    $sections = $this->registry->installScriptSections($agent);

    expect($sections)->toHaveCount(2)
        ->and($sections[0]['name'])->toBe('mod-a')
        ->and($sections[1]['name'])->toBe('mod-b');
});

test('collects shared props from all modules', function () {
    $this->registry->register(createFakeModule('email', ['email']));
    $this->registry->register(createFakeModule('browser', ['browser']));

    $request = Request::create('/');
    $props = $this->registry->sharedProps($request);

    expect($props)->toHaveKey('email', true)
        ->toHaveKey('email_enabled', true)
        ->toHaveKey('browser', true)
        ->toHaveKey('browser_enabled', true);
});

test('lists all registered modules', function () {
    $this->registry->register(createFakeModule('a', []));
    $this->registry->register(createFakeModule('b', []));

    expect($this->registry->all())->toHaveCount(2)
        ->toHaveKeys(['a', 'b']);
});

test('module service provider registers singleton', function () {
    $registry1 = app(ModuleRegistry::class);
    $registry2 = app(ModuleRegistry::class);

    expect($registry1)->toBe($registry2);
});
