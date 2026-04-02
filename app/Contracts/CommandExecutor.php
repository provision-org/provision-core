<?php

namespace App\Contracts;

interface CommandExecutor
{
    public function exec(string $command): string;

    public function execWithRetry(string $command, int $maxAttempts = 3, int $baseDelayMs = 2000): string;

    public function writeFile(string $path, string $content): void;

    public function readFile(string $path): string;

    public function execScript(string $script): string;
}
