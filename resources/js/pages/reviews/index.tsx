import { Head, router, useForm } from '@inertiajs/react';
import { Star } from 'lucide-react';
import ReviewController from '@/actions/App/Http/Controllers/Admin/Reviews/ReviewController';
import { Can } from '@/components/can';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
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

export default function ReviewsIndex({
    reviews,
}: {
    reviews: Paginated<Review>;
}) {
    const goToPage = (page: number) => {
        router.get(
            ReviewController.index().url,
            { page },
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
                        {reviews.data.length === 0 ? (
                            <p className="text-muted-foreground text-sm">
                                No reviews synced yet.
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
