<?php

namespace App\Enums;

enum ModelTier: string
{
    case Efficient = 'efficient';
    case Powerful = 'powerful';
    case Subscription = 'subscription';
    case Bedrock = 'bedrock';

    public function label(): string
    {
        return match ($this) {
            self::Efficient => 'Efficient',
            self::Powerful => 'Powerful',
            self::Subscription => 'Bring your own ChatGPT',
            self::Bedrock => 'Bedrock (your AWS)',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Efficient => 'Fast and cost-effective. Great for routine work like support, ops, and scheduling.',
            self::Powerful => 'Best reasoning and creativity. Ideal for research, sales outreach, and strategy.',
            self::Subscription => 'Use your existing ChatGPT Pro or Team plan. We connect your account in the next step.',
            self::Bedrock => 'Claude models running in your own AWS account via Amazon Bedrock. Model traffic never leaves your cloud.',
        };
    }

    public function estimatedMonthlyCost(): string
    {
        return match ($this) {
            self::Efficient => '~$10/mo',
            self::Powerful => '~$50/mo',
            self::Subscription => 'Included in your ChatGPT plan',
            self::Bedrock => 'Billed to your AWS account',
        };
    }

    public function primaryModel(): string
    {
        return match ($this) {
            self::Efficient => 'claude-haiku-4-5',
            // The Powerful tier's UX copy ("Best reasoning and creativity",
            // "~$50/mo") implies Opus, so the primary should be Opus.
            // Previously this returned Sonnet with Opus only in the fallback
            // chain — fix in issue #31.
            self::Powerful => 'claude-opus-4-6',
            self::Subscription => 'gpt-5.5',
            self::Bedrock => 'bedrock-claude-sonnet-4-6',
        };
    }

    /**
     * @return array<int, string>
     */
    public function fallbackModels(): array
    {
        return match ($this) {
            self::Efficient => ['claude-sonnet-4-6'],
            // Sonnet 4.6 as a single fallback; the previous list duplicated
            // it and pre-emptively included Opus (which is now primary).
            self::Powerful => ['claude-sonnet-4-6'],
            self::Subscription => [],
            // Same-provider fallback only — Bedrock agents must never cross
            // to OpenRouter, or model traffic would leave the customer's AWS.
            self::Bedrock => ['bedrock-claude-haiku-4-5'],
        };
    }

    public function heartbeatModel(): string
    {
        return match ($this) {
            // Heartbeats stay inside the customer's AWS account too.
            self::Bedrock => 'bedrock-claude-haiku-4-5',
            default => 'claude-haiku-4-5',
        };
    }

    public function authProvider(): string
    {
        return match ($this) {
            self::Subscription => 'chatgpt',
            self::Bedrock => 'bedrock',
            default => 'openrouter',
        };
    }
}
