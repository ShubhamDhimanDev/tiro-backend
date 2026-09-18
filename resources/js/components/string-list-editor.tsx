import { PlusIcon, XIcon } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

/**
 * Repeatable list-of-strings editor for JSON `array<string>` columns (e.g.
 * `TyreModel.service_inclusions`, `TyreModel.images`) — add/remove rows of
 * plain text rather than a raw JSON textarea.
 */
export function StringListEditor({
    label,
    values,
    onChange,
    placeholder,
    addLabel = 'Add item',
}: {
    label: string;
    values: string[];
    onChange: (next: string[]) => void;
    placeholder?: string;
    addLabel?: string;
}) {
    const updateAt = (index: number, value: string) => {
        const next = [...values];
        next[index] = value;
        onChange(next);
    };

    const removeAt = (index: number) => {
        onChange(values.filter((_, i) => i !== index));
    };

    return (
        <div className="grid gap-2">
            <span className="text-sm font-medium">{label}</span>

            {values.length === 0 && (
                <p className="text-muted-foreground text-sm">None added yet.</p>
            )}

            {values.map((value, index) => (
                <div key={index} className="flex items-center gap-2">
                    <Input
                        value={value}
                        placeholder={placeholder}
                        onChange={(e) => updateAt(index, e.target.value)}
                    />
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        onClick={() => removeAt(index)}
                        aria-label={`Remove ${label} row ${index + 1}`}
                    >
                        <XIcon className="size-4" />
                    </Button>
                </div>
            ))}

            <Button
                type="button"
                variant="outline"
                size="sm"
                className="w-fit"
                onClick={() => onChange([...values, ''])}
            >
                <PlusIcon className="size-4" />
                {addLabel}
            </Button>
        </div>
    );
}
