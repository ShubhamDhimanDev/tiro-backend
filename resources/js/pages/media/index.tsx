import { Head, router, useForm } from '@inertiajs/react';
import { Copy, ImageOff, Search, Trash2, Upload, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import MediaController from '@/actions/App/Http/Controllers/Admin/Media/MediaController';
import { Can } from '@/components/can';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatDateTime } from '@/lib/date';
import type { Paginated } from '@/types/orders';

type MediaItem = {
    id: number;
    name: string;
    status: 'pending' | 'processing' | 'ready' | 'failed';
    error: string | null;
    url: string | null;
    thumb_url: string | null;
    source_url: string | null;
    size: number | null;
    width: number | null;
    height: number | null;
    used_by: number;
    created_at: string | null;
};

type Filters = { search: string | null; status: string | null };

const ALL = '__all__';

const STATUS_LABELS: Record<MediaItem['status'], string> = {
    pending: 'Queued',
    processing: 'Converting',
    ready: 'Ready',
    failed: 'Failed',
};

function formatBytes(bytes: number | null): string {
    if (bytes === null) {
        return '';
    }

    return bytes > 1024 * 1024
        ? `${(bytes / 1024 / 1024).toFixed(1)} MB`
        : `${Math.max(1, Math.round(bytes / 1024))} KB`;
}

function UploadPanel() {
    const form = useForm<{ files: File[] }>({ files: [] });
    const input = useRef<HTMLInputElement>(null);
    const [dragging, setDragging] = useState(false);

    const send = (files: File[]) => {
        if (files.length === 0) {
            return;
        }

        form.transform(() => ({ files }));
        form.post(MediaController.store().url, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => form.reset(),
            onFinish: () => {
                if (input.current) {
                    input.current.value = '';
                }
            },
        });
    };

    return (
        <div>
            <div
                onDragOver={(e) => {
                    e.preventDefault();
                    setDragging(true);
                }}
                onDragLeave={() => setDragging(false)}
                onDrop={(e) => {
                    e.preventDefault();
                    setDragging(false);
                    send(Array.from(e.dataTransfer.files));
                }}
                className={`flex flex-col items-center gap-2 rounded-lg border-2 border-dashed p-6 text-center transition-colors ${
                    dragging ? 'border-primary bg-primary/5' : ''
                }`}
            >
                <Upload className="text-muted-foreground size-6" />
                <p className="text-sm">
                    Drag images here, or{' '}
                    <Button
                        type="button"
                        variant="link"
                        className="h-auto p-0"
                        disabled={form.processing}
                        onClick={() => input.current?.click()}
                    >
                        choose files
                    </Button>
                </p>
                <p className="text-muted-foreground text-xs">
                    JPG, PNG, WebP, GIF or AVIF, up to 10 MB each. Everything is
                    converted to WebP in the background.
                </p>
                <input
                    ref={input}
                    type="file"
                    multiple
                    accept="image/jpeg,image/png,image/webp,image/gif,image/avif"
                    className="hidden"
                    onChange={(e) => send(Array.from(e.target.files ?? []))}
                />
                {form.processing && (
                    <p className="text-sm">
                        Uploading{' '}
                        {form.progress ? `${form.progress.percentage}%` : '…'}
                    </p>
                )}
            </div>
            <InputError message={form.errors.files} />
            {Object.entries(form.errors)
                .filter(([key]) => key.startsWith('files.'))
                .map(([key, message]) => (
                    <InputError key={key} message={message} />
                ))}
        </div>
    );
}

function Thumb({ item }: { item: MediaItem }) {
    if (item.thumb_url) {
        return (
            <img
                src={item.thumb_url}
                alt={item.name}
                loading="lazy"
                className="size-full object-contain"
            />
        );
    }

    return (
        <div className="text-muted-foreground flex size-full flex-col items-center justify-center gap-1 text-xs">
            <ImageOff className="size-6" />
            {STATUS_LABELS[item.status]}
        </div>
    );
}

function MediaCard({ item }: { item: MediaItem }) {
    const copy = async () => {
        if (!item.url) {
            return;
        }

        try {
            await navigator.clipboard.writeText(item.url);
            toast.success('Image URL copied');
        } catch {
            toast.error('Could not copy — select the URL manually.');
        }
    };

    const remove = () => {
        const used = item.used_by > 0;

        if (
            used ||
            window.confirm(`Delete "${item.name}"? This cannot be undone.`)
        ) {
            router.delete(MediaController.destroy(item.id).url, {
                preserveScroll: true,
            });
        }
    };

    return (
        <li className="flex flex-col overflow-hidden rounded-lg border">
            <div className="bg-muted/40 aspect-square">
                <Thumb item={item} />
            </div>
            <div className="flex flex-1 flex-col gap-2 p-3 text-xs">
                <p className="truncate text-sm font-medium" title={item.name}>
                    {item.name}
                </p>
                <div className="flex flex-wrap items-center gap-1">
                    <Badge
                        variant={
                            item.status === 'failed'
                                ? 'destructive'
                                : item.status === 'ready'
                                  ? 'default'
                                  : 'secondary'
                        }
                    >
                        {STATUS_LABELS[item.status]}
                    </Badge>
                    {item.used_by > 0 && (
                        <Badge variant="outline">
                            Used by {item.used_by} model
                            {item.used_by === 1 ? '' : 's'}
                        </Badge>
                    )}
                    {item.source_url && (
                        <Badge variant="outline">Imported</Badge>
                    )}
                </div>
                <p className="text-muted-foreground">
                    {item.width && item.height
                        ? `${item.width}×${item.height} · `
                        : ''}
                    {formatBytes(item.size)}
                    <br />
                    {formatDateTime(item.created_at)}
                </p>
                {item.error && (
                    <p
                        className="text-destructive line-clamp-3"
                        title={item.error}
                    >
                        {item.error}
                    </p>
                )}
                <Can permission="content.manage">
                    <div className="mt-auto flex gap-1 pt-1">
                        {item.status === 'ready' && (
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                onClick={copy}
                            >
                                <Copy className="size-3.5" />
                                Copy URL
                            </Button>
                        )}
                        {item.status === 'failed' && (
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                onClick={() =>
                                    router.post(
                                        MediaController.retry(item.id).url,
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Retry
                            </Button>
                        )}
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            aria-label={`Delete ${item.name}`}
                            disabled={item.used_by > 0}
                            title={
                                item.used_by > 0
                                    ? 'In use — remove it from the model first'
                                    : 'Delete'
                            }
                            onClick={remove}
                        >
                            <Trash2 className="size-3.5" />
                        </Button>
                    </div>
                </Can>
            </div>
        </li>
    );
}

export default function MediaIndex({
    media,
    filters,
    counts,
}: {
    media: Paginated<MediaItem>;
    filters: Filters;
    counts: { pending: number; failed: number };
}) {
    const [search, setSearch] = useState(filters.search ?? '');

    // While conversions are running, refresh the grid so thumbnails appear.
    useEffect(() => {
        if (counts.pending === 0) {
            return;
        }

        const timer = window.setInterval(
            () => router.reload({ only: ['media', 'counts'] }),
            3000,
        );

        return () => window.clearInterval(timer);
    }, [counts.pending]);

    const apply = (next: Partial<Filters>) => {
        const merged = { ...filters, search: search.trim() || null, ...next };

        router.get(
            MediaController.index().url,
            Object.fromEntries(
                Object.entries(merged).filter(
                    ([, v]) => v !== null && v !== '',
                ),
            ),
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const isFiltering = filters.search !== null || filters.status !== null;

    return (
        <>
            <Head title="Media" />

            <div className="space-y-6 p-4">
                <Heading
                    title="Media"
                    description="Upload images once; they are converted to WebP in the background. Copy a ready image's URL into a tyre model's images."
                />

                <Can permission="content.manage">
                    <Card>
                        <CardContent className="pt-6">
                            <UploadPanel />
                        </CardContent>
                    </Card>
                </Can>

                <Card>
                    <CardContent className="space-y-4 pt-6">
                        <form
                            className="flex flex-wrap items-center gap-2"
                            onSubmit={(e) => {
                                e.preventDefault();
                                apply({});
                            }}
                        >
                            <div className="relative w-full max-w-xs">
                                <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2" />
                                <Input
                                    type="search"
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    placeholder="Search by file name or source URL…"
                                    aria-label="Search media"
                                    className="pl-8"
                                />
                            </div>
                            <Select
                                value={filters.status ?? ALL}
                                onValueChange={(v) =>
                                    apply({ status: v === ALL ? null : v })
                                }
                            >
                                <SelectTrigger
                                    aria-label="Status"
                                    className="w-40"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ALL}>
                                        All statuses
                                    </SelectItem>
                                    <SelectItem value="ready">Ready</SelectItem>
                                    <SelectItem value="pending">
                                        Queued
                                    </SelectItem>
                                    <SelectItem value="processing">
                                        Converting
                                    </SelectItem>
                                    <SelectItem value="failed">
                                        Failed
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <Button type="submit" variant="secondary" size="sm">
                                Search
                            </Button>
                            {isFiltering && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => {
                                        setSearch('');
                                        router.get(
                                            MediaController.index().url,
                                            {},
                                            {
                                                preserveState: true,
                                                replace: true,
                                            },
                                        );
                                    }}
                                >
                                    <X className="size-4" />
                                    Clear
                                </Button>
                            )}
                        </form>

                        {(counts.pending > 0 || counts.failed > 0) && (
                            <p className="text-muted-foreground text-xs">
                                {counts.pending > 0 &&
                                    `${counts.pending} converting in the background. `}
                                {counts.failed > 0 &&
                                    `${counts.failed} failed — use Retry on the card.`}
                            </p>
                        )}

                        {media.data.length === 0 ? (
                            <p className="text-muted-foreground text-sm">
                                {isFiltering
                                    ? 'No images match your search or filter.'
                                    : 'No images yet. Upload some above.'}
                            </p>
                        ) : (
                            <ul className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6">
                                {media.data.map((item) => (
                                    <MediaCard key={item.id} item={item} />
                                ))}
                            </ul>
                        )}

                        {media.last_page > 1 && (
                            <div className="flex items-center justify-between pt-2 text-sm">
                                <span className="text-muted-foreground">
                                    Page {media.current_page} of{' '}
                                    {media.last_page} · {media.total} images
                                </span>
                                <div className="flex gap-2">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        disabled={media.current_page <= 1}
                                        onClick={() =>
                                            router.get(
                                                MediaController.index().url,
                                                {
                                                    ...filters,
                                                    page:
                                                        media.current_page - 1,
                                                },
                                                {
                                                    preserveState: true,
                                                    preserveScroll: true,
                                                },
                                            )
                                        }
                                    >
                                        Previous
                                    </Button>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        disabled={
                                            media.current_page >=
                                            media.last_page
                                        }
                                        onClick={() =>
                                            router.get(
                                                MediaController.index().url,
                                                {
                                                    ...filters,
                                                    page:
                                                        media.current_page + 1,
                                                },
                                                {
                                                    preserveState: true,
                                                    preserveScroll: true,
                                                },
                                            )
                                        }
                                    >
                                        Next
                                    </Button>
                                </div>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

MediaIndex.layout = {
    breadcrumbs: [
        {
            title: 'Media',
            href: MediaController.index(),
        },
    ],
};
