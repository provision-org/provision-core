<?php

namespace App\Services;

use App\Contracts\Modules\ModuleContract;
use App\Models\Agent;
use Illuminate\Http\Request;

class ModuleRegistry
{
    /** @var array<string, ModuleContract> */
    private array $modules = [];

    public function register(ModuleContract $module): void
    {
        $this->modules[$module->name()] = $module;
    }

    public function has(string $name): bool
    {
        return isset($this->modules[$name]);
    }

    public function get(string $name): ?ModuleContract
    {
        return $this->modules[$name] ?? null;
    }

    public function hasCapability(string $capability): bool
    {
        foreach ($this->modules as $module) {
            if (in_array($capability, $module->capabilities(), true)) {
                return true;
            }
        }

        return false;
    }

    public function forCapability(string $capability): ?ModuleContract
    {
        foreach ($this->modules as $module) {
            if (in_array($capability, $module->capabilities(), true)) {
                return $module;
            }
        }

        return null;
    }

    /** @return array<int, array{name: string, script: string}> */
    public function installScriptSections(Agent $agent): array
    {
        $sections = [];

        foreach ($this->modules as $module) {
            $sections = array_merge($sections, $module->installScriptSections($agent));
        }

        return $sections;
    }

    /** @return array<string, mixed> */
    public function sharedProps(Request $request): array
    {
        $props = [];

        foreach ($this->modules as $module) {
            $props[$module->name()] = true;
            $props = array_merge($props, $module->sharedProps($request));
        }

        return $props;
    }

    /** @return array<string, ModuleContract> */
    public function all(): array
    {
        return $this->modules;
    }
}
