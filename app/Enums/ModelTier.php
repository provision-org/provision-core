<?php

namespace App\Enums;

enum ModelTier: string
{
    case Efficient = 'efficient';
    case Powerful = 'powerful';
    case Subscription = 'subscription';

    public function label(): string
    {
        return match ($this) {
            self::Efficient => 'Efficient',
            self::Powerful => 'Powerful',
            self::Subscription => 'Bring your own ChatGPT',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Efficient => 'Fast and cost-effective. Great for routine work like support, ops, and scheduling.',
            self::Powerful => 'Best reasoning and creativity. Ideal for research, sales outreach, and strategy.',
            self::Subscription => 'Use your existing ChatGPT Pro or Team plan. We connect your account in the next step.',
        };
    }

    public function estimatedMonthlyCost(): string
    {
        return match ($this) {
            self::Efficient => '~$10/mo',
            self::Powerful => '~$50/mo',
            self::Subscription => 'Included in your ChatGPT plan',
        };
    }

    public function primaryModel(): string
    {
        return match ($this) {
            self::Efficient => 'claude-haiku-4-5',
            self::Powerful => 'claude-sonnet-4-6',
            self::Subscription => 'gpt-5.5',
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
            self::Subscription => [],
        };
    }

    public function heartbeatModel(): string
    {
        return 'claude-haiku-4-5';
    }

    public function authProvider(): string
    {
        return match ($this) {
            self::Subscription => 'chatgpt',
            default => 'openrouter',
        };
    }
}
