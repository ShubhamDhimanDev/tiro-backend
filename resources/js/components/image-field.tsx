import { ImageIcon, XIcon } from 'lucide-react';
import { useState } from 'react';
import { MediaPickerDialog } from '@/components/media-picker-dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/**
 * Single image URL field: a text input (so an external URL can still be
 * pasted), a preview, and a "Choose from media" button.
 */
export function ImageField({
    id,
    label,
    value,
    onChange,
    placeholder = 'https://…',
}: {
    id: string;
    label: string;
    value: string;
    onChange: (next: string) => void;
    placeholder?: string;
}) {
    const [open, setOpen] = useState(false);

    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>{label}</Label>
            <div className="flex items-center gap-2">
                {value && (
                    <img
                        src={value}
                        alt=""
                        className="bg-muted/40 size-10 shrink-0 rounded border object-contain"
                        onError={(e) => {
                            e.currentTarget.style.visibility = 'hidden';
                        }}
                    />
                )}
                <Input
                    id={id}
                    value={value}
                    placeholder={placeholder}
                    onChange={(e) => onChange(e.target.value)}
                />
                {value && (
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        aria-label={`Clear ${label}`}
                        onClick={() => onChange('')}
                    >
                        <XIcon className="size-4" />
                    </Button>
                )}
            </div>
            <Button
                type="button"
                variant="outline"
                size="sm"
                className="w-fit"
                onClick={() => setOpen(true)}
            >
                <ImageIcon className="size-4" />
                Choose from media
            </Button>
            <MediaPickerDialog
                open={open}
                onOpenChange={setOpen}
                onSelect={([url]) => onChange(url)}
            />
        </div>
    );
}
