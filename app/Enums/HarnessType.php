<?php

namespace App\Enums;

enum HarnessType: string
{
    case OpenClaw = 'openclaw';
    case Hermes = 'hermes';

    public function label(): string
    {
        return match ($this) {
            self::OpenClaw => 'OpenClaw',
            self::Hermes => 'Hermes',
        };
    }
}
