import { useState } from 'react';
import ModelSelector from '@/components/model-selector';
import { Button } from '@/components/ui/button';

type ProviderInfo = {
    provider: string;
    is_active: boolean;
};

export default function FallbackChain({
    value,
    onChange,
    availableProviders,
}: {
    value: string[];
    onChange: (value: string[]) => void;
    availableProviders: ProviderInfo[];
}) {
    const [adding, setAdding] = useState(false);

    function moveUp(index: number) {
        if (index === 0) return;
        const next = [...value];
        [next[index - 1], next[index]] = [next[index], next[index - 1]];
        onChange(next);
    }

    function moveDown(index: number) {
        if (index === value.length - 1) return;
        const next = [...value];
        [next[index], next[index + 1]] = [next[index + 1], next[index]];
        onChange(next);
    }

    function remove(index: number) {
        onChange(value.filter((_, i) => i !== index));
    }

    function addModel(model: string) {
        if (model) {
            onChange([...value, model]);
        }
        setAdding(false);
    }

    return (
        <div className="space-y-3">
            {value.length === 0 && (
                <p className="text-sm text-muted-foreground">
                    No fallback models configured.
                </p>
            )}

            {value.map((model, index) => (
                <div
                    key={`${model}-${index}`}
                    className="flex items-center justify-between rounded-md border px-3 py-2"
                >
                    <div className="flex items-center gap-2">
                        <span className="text-xs text-muted-foreground">
                            {index + 1}.
                        </span>
                        <span className="text-sm">{model}</span>
                    </div>

                    <div className="flex items-center gap-1">
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => moveUp(index)}
                            disabled={index === 0}
                        >
                            Up
                        </Button>
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => moveDown(index)}
                            disabled={index === value.length - 1}
                        >
                            Down
                        </Button>
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => remove(index)}
                        >
                            Remove
                        </Button>
                    </div>
                </div>
            ))}

            {adding ? (
                <ModelSelector
                    value=""
                    onChange={addModel}
                    availableProviders={availableProviders}
                />
            ) : (
                <Button
                    variant="outline"
                    size="sm"
                    onClick={() => setAdding(true)}
                >
                    Add fallback model
                </Button>
            )}
        </div>
    );
}
