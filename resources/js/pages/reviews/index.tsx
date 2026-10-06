import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Search, Star, X } from 'lucide-react';
import ReviewController from '@/actions/App/Http/Controllers/Admin/Reviews/ReviewController';
import { Can } from '@/components/can';
import Heading from '@/components/heading';
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
import { cn } from '@/lib/utils';
import type { Paginated } from '@/types/orders';
import type { Review } from '@/types/reviews';
import type { BreadcrumbItem } from '@/types';

function StarRating({ rating }: { rating: number }) {
    return (
        <div
            className="flex items-center gap-0.5"
            aria-label={`${rating} out of 5 stars`}
        >
            {[1, 2, 3, 4, 5].map((star) => (
                <Star
                    key={star}
                    className={cn(
                        'size-4',
                        star <= rating
                            ? 'fill-amber-400 text-amber-400'
                            : 'text-muted-foreground',
                    )}
                />
            ))}
        </div>
    );
}

function ModerationToggle({ review }: { review: Review }) {
    const form = useForm({ is_hidden: !review.is_hidden });

    const toggle = () => {
        form.transform(() => ({ is_hidden: !review.is_hidden }));
        form.patch(ReviewController.update(review.id).url, {
            preserveScroll: true,
        });
    };

    return (
        <Button
            variant="outline"
            size="sm"
            disabled={form.processing}
            onClick={toggle}
        >
            {review.is_hidden ? 'Restore' : 'Hide'}
        </Button>
    );
}

function ResyncButton() {
    const form = useForm({});

    const resync = () => {
        form.post(ReviewController.resync().url, {
            preserveScroll: true,
        });
    };

    return (
        <Button disabled={form.processing} onClick={resync}>
            {form.processing ? 'Syncing…' : 'Sync now'}
        </Button>
    );
}

type ReviewFilters = {
    search: string | null;
    rating: number | null;
    visibility: 'hidden' | 'visible' | null;
};

const ALL = '__all__';

function ReviewFilterBar({ filters }: { filters: ReviewFilters }) {
    const [search, setSearch] = useState(filters.search ?? '');

    const apply = (next: Partial<ReviewFilters>) => {
        const merged = { ...filters, search: search.trim() || null, ...next };

        router.get(
            ReviewController.index().url,
            Object.fromEntries(
                Object.entries(merged).filter(
                    ([, v]) => v !== null && v !== '',
                ),
            ),
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const isFiltering =
        filters.search !== null ||
        filters.rating !== null ||
        filters.visibility !== null;

    return (
        <form
            className="mb-4 flex flex-wrap items-center gap-2"
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
                    placeholder="Search by author or review text…"
                    aria-label="Search reviews"
                    className="pl-8"
                />
            </div>
            <Select
                value={filters.rating ? String(filters.rating) : ALL}
                onValueChange={(v) =>
                    apply({ rating: v === ALL ? null : Number(v) })
                }
            >
                <SelectTrigger aria-label="Rating" className="w-40">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value={ALL}>All ratings</SelectItem>
                    {[5, 4, 3, 2, 1].map((n) => (
                        <SelectItem key={n} value={String(n)}>
                            {n} star{n === 1 ? '' : 's'}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
            <Select
                value={filters.visibility ?? ALL}
                onValueChange={(v) =>
                    apply({
                        visibility:
                            v === ALL ? null : (v as 'hidden' | 'visible'),
                    })
                }
            >
                <SelectTrigger aria-label="Visibility" className="w-40">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value={ALL}>All reviews</SelectItem>
                    <SelectItem value="visible">Visible</SelectItem>
                    <SelectItem value="hidden">Hidden</SelectItem>
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
                            ReviewController.index().url,
                            {},
                            { preserveState: true, replace: true },
                        );
                    }}
                >
                    <X className="size-4" />
                    Clear
                </Button>
            )}
        </form>
    );
}

export default function ReviewsIndex({
    reviews,
    filters,
}: {
    reviews: Paginated<Review>;
    filters: ReviewFilters;
}) {
    const goToPage = (page: number) => {
        router.get(
            ReviewController.index().url,
            { ...filters, page },
            { preserveState: true, preserveScroll: true },
        );
    };

    return (
        <>
            <Head title="Reviews" />

            <div className="space-y-6 p-4">
                <div className="flex items-start justify-between gap-4">
                    <Heading
                        title="Reviews"
                        description="Every synced Google review, hidden and visible alike — moderate what shows on the public storefront."
                    />

                    <Can permission="content.manage">
                        <ResyncButton />
                    </Can>
                </div>

                <Card>
                    <CardContent className="pt-6">
                        <ReviewFilterBar filters={filters} />
                        {reviews.data.length === 0 ? (
                            <p className="text-muted-foreground text-sm">
                                {filters.search ||
                                filters.rating ||
                                filters.visibility
                                    ? 'No reviews match your search or filters.'
                                    : 'No reviews synced yet.'}
                            </p>
                        ) : (
                            <>
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b text-left">
                                            <th className="py-2 font-medium">
                                                Author
                                            </th>
                                            <th className="py-2 font-medium">
                                                Rating
                                            </th>
                                            <th className="py-2 font-medium">
                                                Review
                                            </th>
                                            <th className="py-2 font-medium">
                                                Published
                                            </th>
                                            <th className="py-2 font-medium">
                                                Status
                                            </th>
                                            <th className="py-2 font-medium" />
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {reviews.data.map((review) => (
                                            <tr
                                                key={review.id}
                                                className={cn(
                                                    'border-b last:border-0',
                                                    review.is_hidden &&
                                                        'opacity-60',
                                                )}
                                            >
                                                <td className="py-2 font-medium">
                                                    {review.author_name}
                                                    <div className="text-muted-foreground text-xs">
                                                        via{' '}
                                                        {review.source ===
                                                        'google'
                                                            ? 'Google'
                                                            : review.source}
                                                        {review.review_url && (
                                                            <>
                                                                {' · '}
                                                                <a
                                                                    href={
                                                                        review.review_url
                                                                    }
                                                                    target="_blank"
                                                                    rel="noopener noreferrer"
                                                                    className="underline underline-offset-2"
                                                                >
                                                                    View
                                                                    original
                                                                </a>
                                                            </>
                                                        )}
                                                    </div>
                                                </td>
                                                <td className="py-2">
                                                    <StarRating
                                                        rating={review.rating}
                                                    />
                                                </td>
                                                <td className="max-w-md py-2">
                                                    {review.body ? (
                                                        <span
                                                            className="line-clamp-2"
                                                            title={review.body}
                                                        >
                                                            {review.body}
                                                        </span>
                                                    ) : (
                                                        <span className="text-muted-foreground italic">
                                                            No written review
                                                            (star rating only)
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="text-muted-foreground py-2 whitespace-nowrap">
                                                    {new Date(
                                                        review.published_at,
                                                    ).toLocaleDateString(
                                                        'en-AU',
                                                    )}
                                                </td>
                                                <td className="py-2">
                                                    <Badge
                                                        variant={
                                                            review.is_hidden
                                                                ? 'destructive'
                                                                : 'secondary'
                                                        }
                                                    >
                                                        {review.is_hidden
                                                            ? 'Hidden'
                                                            : 'Visible'}
                                                    </Badge>
                                                </td>
                                                <td className="py-2 text-right whitespace-nowrap">
                                                    <Can permission="content.manage">
                                                        <ModerationToggle
                                                            review={review}
                                                        />
                                                    </Can>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>

                                <div className="text-muted-foreground flex items-center justify-between pt-4 text-sm">
                                    <span>
                                        Showing {reviews.from ?? 0}–
                                        {reviews.to ?? 0} of {reviews.total}
                                    </span>
                                    <div className="flex gap-2">
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            disabled={reviews.current_page <= 1}
                                            onClick={() =>
                                                goToPage(
                                                    reviews.current_page - 1,
                                                )
                                            }
                                        >
                                            Previous
                                        </Button>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            disabled={
                                                reviews.current_page >=
                                                reviews.last_page
                                            }
                                            onClick={() =>
                                                goToPage(
                                                    reviews.current_page + 1,
                                                )
                                            }
                                        >
                                            Next
                                        </Button>
                                    </div>
                                </div>
                            </>
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

ReviewsIndex.layout = {
    breadcrumbs: [
        { title: 'Reviews', href: ReviewController.index().url },
    ] satisfies BreadcrumbItem[],
};
