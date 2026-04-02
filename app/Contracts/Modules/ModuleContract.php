<?php

namespace App\Contracts\Modules;

use App\Models\Agent;
use Illuminate\Http\Request;

interface ModuleContract
{
    public function name(): string;

    public function label(): string;

    public function version(): string;

    /** @return array<int, string> */
    public function capabilities(): array;

    /** @return array<int, array{name: string, script: string}> */
    public function installScriptSections(Agent $agent): array;

    public function cleanupAgent(Agent $agent): void;

    /** @return array<string, mixed> */
    public function sharedProps(Request $request): array;
}
