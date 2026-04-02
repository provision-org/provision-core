<?php

namespace App\Enums;

enum ModelTier: string
{
    case Efficient = 'efficient';
    case Powerful = 'powerful';

    public function label(): string
    {
        return match ($this) {
            self::Efficient => 'Efficient',
            self::Powerful => 'Powerful',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Efficient => 'Fast and cost-effective. Great for routine work like support, ops, and scheduling.',
            self::Powerful => 'Best reasoning and creativity. Ideal for research, sales outreach, and strategy.',
        };
    }

    public function estimatedMonthlyCost(): string
    {
        return match ($this) {
            self::Efficient => '~$10/mo',
            self::Powerful => '~$50/mo',
        };
    }

    public function primaryModel(): string
    {
        return match ($this) {
            self::Efficient => 'claude-haiku-4-5',
            self::Powerful => 'claude-sonnet-4-6',
        };
    }

    /**
     * @return array<int, string>
     */
    public function fallbackModels(): array
    {
        return match ($this) {
            self::Efficient => ['claude-sonnet-4-6'],
            self::Powerful => ['claude-opus-4-6', 'claude-sonnet-4-6'],
        };
    }

    public function heartbeatModel(): string
    {
        return 'claude-haiku-4-5';
    }
}
