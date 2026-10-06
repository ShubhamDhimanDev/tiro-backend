import { ImageIcon, PlusIcon, XIcon } from 'lucide-react';
import { useState } from 'react';
import { MediaPickerDialog } from '@/components/media-picker-dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

/**
 * Repeatable list of image URLs (e.g. `TyreModel.images`): a thumbnail and
 * editable URL per row, "Choose from media" (multi-select) to add library
 * images, and "Add image URL" for an external link.
 */
export function ImageListEditor({
    label,
    values,
    onChange,
}: {
    label: string;
    values: string[];
    onChange: (next: string[]) => void;
}) {
    const [open, setOpen] = useState(false);

    const updateAt = (index: number, value: string) => {
        const next = [...values];
        next[index] = value;
        onChange(next);
    };

    return (
        <div className="grid gap-2">
            <span className="text-sm font-medium">{label}</span>

            {values.length === 0 && (
                <p className="text-muted-foreground text-sm">None added yet.</p>
            )}

            {values.map((value, index) => (
                <div key={index} className="flex items-center gap-2">
                    {value ? (
                        <img
                            src={value}
                            alt=""
                            className="bg-muted/40 size-10 shrink-0 rounded border object-contain"
                            onError={(e) => {
                                e.currentTarget.style.visibility = 'hidden';
                            }}
                        />
                    ) : (
                        <div className="size-10 shrink-0 rounded border border-dashed" />
                    )}
                    <Input
                        value={value}
                        placeholder="https://…"
                        onChange={(e) => updateAt(index, e.target.value)}
                    />
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        onClick={() =>
                            onChange(values.filter((_, i) => i !== index))
                        }
                        aria-label={`Remove ${label} row ${index + 1}`}
                    >
                        <XIcon className="size-4" />
                    </Button>
                </div>
            ))}

            <div className="flex flex-wrap gap-2">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() => setOpen(true)}
                >
                    <ImageIcon className="size-4" />
                    Choose from media
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() => onChange([...values, ''])}
                >
                    <PlusIcon className="size-4" />
                    Add image URL
                </Button>
            </div>

            <MediaPickerDialog
                open={open}
                onOpenChange={setOpen}
                multiple
                onSelect={(urls) =>
                    onChange([
                        ...values.filter((v) => v !== ''),
                        ...urls.filter((u) => !values.includes(u)),
                    ])
                }
            />
        </div>
    );
}
