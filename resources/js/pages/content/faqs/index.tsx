import { Head, router } from '@inertiajs/react';
import ContentPageController from '@/actions/App/Http/Controllers/Admin/Content/ContentPageController';
import FaqController from '@/actions/App/Http/Controllers/Admin/Content/FaqController';
import { Can } from '@/components/can';
import { FaqFormDialog } from '@/components/faq-form-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { pageStatusBadgeVariant } from '@/lib/enums';
import type { ContentPageType, Faq } from '@/types/content';
import type { BreadcrumbItem } from '@/types';

function DeleteFaqButton({ faq }: { faq: Faq }) {
    const destroy = () => {
        if (!confirm('Delete this FAQ?')) {
            return;
        }

        router.delete(FaqController.destroy(faq.id).url, {
            preserveScroll: true,
        });
    };

    return (
        <Button variant="outline" size="sm" onClick={destroy}>
            Delete
        </Button>
    );
}

export default function FaqsIndex({
    faqs,
    contentPages,
}: {
    faqs: Faq[];
    contentPages: { id: number; title: string; type: ContentPageType }[];
}) {
    return (
        <>
            <Head title="FAQs" />

            <div className="flex justify-end">
                <Can permission="content.manage">
                    <FaqFormDialog contentPages={contentPages} />
                </Can>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>FAQs</CardTitle>
                    <CardDescription>
                        Global FAQs (shared/site-wide, including the "pdp"
                        category the PDP's shared FAQ block filters on) and FAQs
                        scoped to one specific content page's own block.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    {faqs.length === 0 ? (
                        <p className="text-muted-foreground text-sm">
                            No FAQs yet.
                        </p>
                    ) : (
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b text-left">
                                    <th className="py-2 font-medium">
                                        Question
                                    </th>
                                    <th className="py-2 font-medium">Scope</th>
                                    <th className="py-2 font-medium">
                                        Category
                                    </th>
                                    <th className="py-2 font-medium">
                                        Sort order
                                    </th>
                                    <th className="py-2 font-medium">Status</th>
                                    <th className="py-2 font-medium" />
                                </tr>
                            </thead>
                            <tbody>
                                {faqs.map((faq) => (
                                    <tr
                                        key={faq.id}
                                        className="border-b last:border-0"
                                    >
                                        <td className="max-w-md py-2 font-medium">
                                            {faq.question}
                                        </td>
                                        <td className="text-muted-foreground py-2">
                                            {faq.content_page ? (
                                                faq.content_page.title
                                            ) : (
                                                <Badge variant="secondary">
                                                    Global
                                                </Badge>
                                            )}
                                        </td>
                                        <td className="text-muted-foreground py-2">
                                            {faq.category ?? '—'}
                                        </td>
                                        <td className="text-muted-foreground py-2">
                                            {faq.sort_order}
                                        </td>
                                        <td className="py-2">
                                            <Badge
                                                variant={pageStatusBadgeVariant(
                                                    faq.status,
                                                )}
                                            >
                                                {faq.status}
                                            </Badge>
                                        </td>
                                        <td className="space-x-2 py-2 text-right whitespace-nowrap">
                                            <Can permission="content.manage">
                                                <FaqFormDialog
                                                    faq={faq}
                                                    contentPages={contentPages}
                                                />
                                                <DeleteFaqButton faq={faq} />
                                            </Can>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </CardContent>
            </Card>
        </>
    );
}

FaqsIndex.layout = {
    breadcrumbs: [
        { title: 'Content', href: ContentPageController.index().url },
        { title: 'FAQs', href: FaqController.index().url },
    ] satisfies BreadcrumbItem[],
};
