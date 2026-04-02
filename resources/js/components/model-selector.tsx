import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectLabel,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

type ProviderInfo = {
    provider: string;
    is_active: boolean;
};

const providerLabels: Record<string, string> = {
    anthropic: 'Anthropic',
    openai: 'OpenAI',
    open_router: 'OpenRouter',
};

const providerModels: Record<string, { value: string; label: string }[]> = {
    anthropic: [
        { value: 'claude-opus-4-6', label: 'Claude Opus 4.6' },
        { value: 'claude-sonnet-4-5-20250929', label: 'Claude Sonnet 4.5' },
        { value: 'claude-haiku-4-5-20251001', label: 'Claude Haiku 4.5' },
    ],
    openai: [
        { value: 'gpt-4o', label: 'GPT-4o' },
        { value: 'gpt-4o-mini', label: 'GPT-4o Mini' },
        { value: 'o1', label: 'o1' },
        { value: 'o1-mini', label: 'o1-mini' },
    ],
    open_router: [{ value: 'openrouter/auto', label: 'Auto' }],
};

export default function ModelSelector({
    value,
    onChange,
    availableProviders,
}: {
    value: string;
    onChange: (value: string) => void;
    availableProviders: ProviderInfo[];
}) {
    const activeProviders = availableProviders.filter((p) => p.is_active);

    return (
        <Select value={value} onValueChange={onChange}>
            <SelectTrigger>
                <SelectValue placeholder="Select a model..." />
            </SelectTrigger>
            <SelectContent>
                {activeProviders.length === 0 ? (
                    <SelectItem value="__none__" disabled>
                        No providers configured
                    </SelectItem>
                ) : (
                    activeProviders.map((provider) => (
                        <SelectGroup key={provider.provider}>
                            <SelectLabel>
                                {providerLabels[provider.provider] ??
                                    provider.provider}
                            </SelectLabel>
                            {(providerModels[provider.provider] ?? []).map(
                                (model) => (
                                    <SelectItem
                                        key={model.value}
                                        value={model.value}
                                    >
                                        {model.label}
                                    </SelectItem>
                                ),
                            )}
                        </SelectGroup>
                    ))
                )}
            </SelectContent>
        </Select>
    );
}
