import { Check, Search, Upload } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import MediaController from '@/actions/App/Http/Controllers/Admin/Media/MediaController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';

type PickerItem = {
    id: number;
    name: string;
    url: string;
    thumb_url: string | null;
    width: number | null;
    height: number | null;
};

type PickerPage = {
    data: PickerItem[];
    current_page: number;
    last_page: number;
    total: number;
};

type UploadStatus = {
    id: number;
    name: string;
    status: 'pending' | 'processing' | 'ready' | 'failed';
    error: string | null;
    url: string | null;
};

const POLL_MS = 1500;
const POLL_LIMIT = 80; // ~2 minutes

function csrfHeaders(): Record<string, string> {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);

    return {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(match ? { 'X-XSRF-TOKEN': decodeURIComponent(match[1]) } : {}),
    };
}

/**
 * "Choose from media" dialog: browse and search the ready images in the media
 * library, or upload new ones right here (they convert to WebP in the
 * background and are picked automatically once ready). `multiple` allows
 * ticking several images at once (used by the tyre model image list).
 */
export function MediaPickerDialog({
    open,
    onOpenChange,
    onSelect,
    multiple = false,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onSelect: (urls: string[]) => void;
    multiple?: boolean;
}) {
    const [search, setSearch] = useState('');
    const [query, setQuery] = useState('');
    const [page, setPage] = useState(1);
    const [result, setResult] = useState<PickerPage | null>(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [selected, setSelected] = useState<string[]>([]);
    const [uploading, setUploading] = useState(false);
    const [uploadError, setUploadError] = useState<string | null>(null);
    const [converting, setConverting] = useState<UploadStatus[]>([]);
    const fileInput = useRef<HTMLInputElement>(null);

    const load = useCallback(async () => {
        setLoading(true);
        setError(null);

        try {
            const params = new URLSearchParams({ page: String(page) });

            if (query) {
                params.set('search', query);
            }

            const response = await fetch(
                `${MediaController.picker().url}?${params.toString()}`,
                { headers: csrfHeaders(), credentials: 'same-origin' },
            );

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            setResult((await response.json()) as PickerPage);
        } catch {
            setError('Could not load the media library. Try again.');
        } finally {
            setLoading(false);
        }
    }, [page, query]);

    useEffect(() => {
        if (open) {
            void load();
        }
    }, [open, load]);

    useEffect(() => {
        if (open) {
            setSelected([]);
            setUploadError(null);
            setConverting([]);
        }
    }, [open]);

    const choose = useCallback(
        (urls: string[]) => {
            if (urls.length === 0) {
                return;
            }

            if (multiple) {
                setSelected((current) => [
                    ...current,
                    ...urls.filter((u) => !current.includes(u)),
                ]);

                return;
            }

            onSelect([urls[0]]);
            onOpenChange(false);
        },
        [multiple, onOpenChange, onSelect],
    );

    const toggle = (url: string) => {
        if (!multiple) {
            choose([url]);

            return;
        }

        setSelected((current) =>
            current.includes(url)
                ? current.filter((u) => u !== url)
                : [...current, url],
        );
    };

    const trackConversion = useCallback(
        async (ids: number[]) => {
            for (let attempt = 0; attempt < POLL_LIMIT; attempt++) {
                await new Promise((resolve) => setTimeout(resolve, POLL_MS));

                const response = await fetch(
                    `${MediaController.status().url}?ids=${ids.join(',')}`,
                    { headers: csrfHeaders(), credentials: 'same-origin' },
                );

                if (!response.ok) {
                    continue;
                }

                const { data } = (await response.json()) as {
                    data: UploadStatus[];
                };

                setConverting(data);

                if (
                    data.every(
                        (i) => i.status === 'ready' || i.status === 'failed',
                    )
                ) {
                    choose(
                        data
                            .filter((i) => i.status === 'ready' && i.url)
                            .map((i) => i.url as string),
                    );
                    setPage(1);
                    setQuery('');
                    setSearch('');
                    void load();

                    return;
                }
            }

            setUploadError(
                'Still converting — it will appear in the library shortly. Use refresh below.',
            );
        },
        [choose, load],
    );

    const upload = async (files: File[]) => {
        if (files.length === 0) {
            return;
        }

        setUploading(true);
        setUploadError(null);

        try {
            const body = new FormData();
            files.forEach((file) => body.append('files[]', file));

            const response = await fetch(MediaController.store().url, {
                method: 'POST',
                headers: csrfHeaders(),
                credentials: 'same-origin',
                body,
            });

            if (!response.ok) {
                const problem = (await response.json().catch(() => null)) as {
                    message?: string;
                } | null;

                throw new Error(problem?.message ?? `HTTP ${response.status}`);
            }

            const { data } = (await response.json()) as {
                data: { id: number; name: string }[];
            };

            setConverting(
                data.map((i) => ({
                    ...i,
                    status: 'pending' as const,
                    error: null,
                    url: null,
                })),
            );
            setUploading(false);
            await trackConversion(data.map((i) => i.id));
        } catch (e) {
            setUploadError(
                e instanceof Error ? e.message : 'The upload failed.',
            );
        } finally {
            setUploading(false);
            if (fileInput.current) {
                fileInput.current.value = '';
            }
        }
    };

    const busy =
        uploading ||
        converting.some(
            (i) => i.status === 'pending' || i.status === 'processing',
        );

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-3xl">
                <DialogHeader>
                    <DialogTitle>Choose from media</DialogTitle>
                    <DialogDescription>
                        {multiple
                            ? 'Tick one or more images, then add them.'
                            : 'Click an image to use it.'}{' '}
                        Only images that finished converting are listed.
                    </DialogDescription>
                </DialogHeader>

                <div className="flex flex-wrap items-center gap-2">
                    <form
                        className="flex min-w-0 flex-1 items-center gap-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            setPage(1);
                            setQuery(search.trim());
                        }}
                    >
                        <div className="relative flex-1">
                            <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2" />
                            <Input
                                type="search"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Search by file name…"
                                aria-label="Search media"
                                className="pl-8"
                            />
                        </div>
                        <Button type="submit" variant="secondary" size="sm">
                            Search
                        </Button>
                    </form>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        disabled={busy}
                        onClick={() => fileInput.current?.click()}
                    >
                        <Upload className="size-4" />
                        {uploading ? 'Uploading…' : 'Upload'}
                    </Button>
                    <input
                        ref={fileInput}
                        type="file"
                        multiple={multiple}
                        accept="image/jpeg,image/png,image/webp,image/gif,image/avif"
                        className="hidden"
                        aria-label="Upload images"
                        onChange={(e) =>
                            void upload(Array.from(e.target.files ?? []))
                        }
                    />
                </div>

                {converting.length > 0 && (
                    <ul className="space-y-1 text-xs" aria-live="polite">
                        {converting.map((item) => (
                            <li
                                key={item.id}
                                className={
                                    item.status === 'failed'
                                        ? 'text-destructive'
                                        : 'text-muted-foreground'
                                }
                            >
                                {item.name}:{' '}
                                {item.status === 'failed'
                                    ? `failed — ${item.error ?? 'could not convert'}`
                                    : item.status === 'ready'
                                      ? 'ready'
                                      : 'converting to WebP…'}
                            </li>
                        ))}
                    </ul>
                )}

                {uploadError && (
                    <p className="text-destructive text-sm">{uploadError}</p>
                )}

                {error && <p className="text-destructive text-sm">{error}</p>}

                {!error && result && result.data.length === 0 && !loading && (
                    <p className="text-muted-foreground text-sm">
                        {query
                            ? 'No images match your search.'
                            : 'The media library has no ready images yet. Upload one above.'}
                    </p>
                )}

                {result && result.data.length > 0 && (
                    <ul
                        className="grid grid-cols-3 gap-3 sm:grid-cols-4 md:grid-cols-6"
                        aria-busy={loading}
                    >
                        {result.data.map((item) => {
                            const isSelected = selected.includes(item.url);

                            return (
                                <li key={item.id}>
                                    <button
                                        type="button"
                                        onClick={() => toggle(item.url)}
                                        aria-pressed={
                                            multiple ? isSelected : undefined
                                        }
                                        title={item.name}
                                        className={`focus-visible:ring-ring bg-muted/40 relative block aspect-square w-full overflow-hidden rounded-md border focus-visible:ring-2 focus-visible:outline-none ${
                                            isSelected
                                                ? 'ring-primary ring-2'
                                                : 'hover:border-primary'
                                        }`}
                                    >
                                        <img
                                            src={item.thumb_url ?? item.url}
                                            alt={item.name}
                                            loading="lazy"
                                            className="size-full object-contain"
                                        />
                                        {isSelected && (
                                            <span className="bg-primary text-primary-foreground absolute top-1 right-1 rounded-full p-0.5">
                                                <Check className="size-3" />
                                            </span>
                                        )}
                                    </button>
                                </li>
                            );
                        })}
                    </ul>
                )}

                {result && result.last_page > 1 && (
                    <div className="flex items-center justify-between text-sm">
                        <span className="text-muted-foreground">
                            Page {result.current_page} of {result.last_page}
                        </span>
                        <div className="flex gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                disabled={page <= 1 || loading}
                                onClick={() => setPage((p) => p - 1)}
                            >
                                Previous
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                disabled={page >= result.last_page || loading}
                                onClick={() => setPage((p) => p + 1)}
                            >
                                Next
                            </Button>
                        </div>
                    </div>
                )}

                <DialogFooter className="items-center sm:justify-between">
                    <button
                        type="button"
                        className="text-muted-foreground text-xs underline"
                        onClick={() => void load()}
                    >
                        Refresh list
                    </button>
                    {multiple && (
                        <Button
                            type="button"
                            disabled={selected.length === 0}
                            onClick={() => {
                                onSelect(selected);
                                onOpenChange(false);
                            }}
                        >
                            Add {selected.length || ''} selected
                        </Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
