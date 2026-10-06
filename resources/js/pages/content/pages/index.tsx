import { ListToolbar, useListFilter } from '@/components/list-toolbar';
import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import ContentPageController from '@/actions/App/Http/Controllers/Admin/Content/ContentPageController';
import FaqController from '@/actions/App/Http/Controllers/Admin/Content/FaqController';
import { Can } from '@/components/can';
import { FaqFormDialog } from '@/components/faq-form-dialog';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import {
    CONTENT_PAGE_TYPE_OPTIONS,
    PAGE_STATUS_OPTIONS,
    pageStatusBadgeVariant,
} from '@/lib/enums';
import type { ContentPage, ContentPageType, PageStatus } from '@/types/content';
import type { BreadcrumbItem } from '@/types';

type ContentPageFormData = {
    type: ContentPageType;
    title: string;
    slug: string;
    excerpt: string;
    body: string;
    featured_image_path: string;
    meta_title: string;
    meta_description: string;
    og_image_path: string;
    category: string;
    status: PageStatus;
    published_at: string;
    service_zone_id: string;
    promotion_id: string;
    sort_order: string;
};

const CONTENT_PAGE_TYPE_LABELS: Record<ContentPageType, string> =
    Object.fromEntries(
        CONTENT_PAGE_TYPE_OPTIONS.map((o) => [o.value, o.label]),
    ) as Record<ContentPageType, string>;

function PageFaqSection({
    contentPage,
    contentPages,
}: {
    contentPage: ContentPage;
    contentPages: { id: number; title: string; type: ContentPageType }[];
}) {
    const faqs = contentPage.faqs ?? [];

    const remove = (faqId: number) => {
        if (!confirm('Delete this FAQ?')) {
            return;
        }
        router.delete(FaqController.destroy(faqId).url, {
            preserveScroll: true,
        });
    };

    return (
        <div className="grid gap-2">
            <Label>FAQs on this page</Label>
            <div className="max-h-56 space-y-1 overflow-y-auto rounded-md border p-2">
                {faqs.length === 0 ? (
                    <p className="text-muted-foreground text-sm">
                        No FAQs scoped to this page yet.
                    </p>
                ) : (
                    faqs.map((faq) => (
                        <div
                            key={faq.id}
                            className="flex items-center justify-between gap-2 rounded px-2 py-1 text-sm"
                        >
                            <span className="truncate">{faq.question}</span>
                            <span className="flex shrink-0 items-center gap-1">
                                <FaqFormDialog
                                    faq={faq}
                                    contentPages={contentPages}
                                    fixedContentPageId={contentPage.id}
                                    trigger={
                                        <Button variant="ghost" size="sm">
                                            Edit
                                        </Button>
                                    }
                                />
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => remove(faq.id)}
                                >
                                    Delete
                                </Button>
                            </span>
                        </div>
                    ))
                )}
            </div>
            <div className="flex justify-end">
                <FaqFormDialog
                    contentPages={contentPages}
                    fixedContentPageId={contentPage.id}
                    trigger={
                        <Button variant="outline" size="sm">
                            Add FAQ
                        </Button>
                    }
                />
            </div>
        </div>
    );
}

function ContentPageFormDialog({
    contentPage,
    serviceZones,
    promotions,
    contentPages,
}: {
    contentPage?: ContentPage;
    serviceZones: { id: number; name: string }[];
    promotions: { id: number; name: string }[];
    contentPages: { id: number; title: string; type: ContentPageType }[];
}) {
    const isEdit = !!contentPage;
    const [open, setOpen] = useState(false);

    const form = useForm<ContentPageFormData>({
        type: contentPage?.type ?? 'page',
        title: contentPage?.title ?? '',
        slug: contentPage?.slug ?? '',
        excerpt: contentPage?.excerpt ?? '',
        body: contentPage?.body ?? '',
        featured_image_path: contentPage?.featured_image_path ?? '',
        meta_title: contentPage?.meta_title ?? '',
        meta_description: contentPage?.meta_description ?? '',
        og_image_path: contentPage?.og_image_path ?? '',
        category: contentPage?.category ?? '',
        status: contentPage?.status ?? 'draft',
        published_at: contentPage?.published_at?.slice(0, 10) ?? '',
        service_zone_id: contentPage?.service_zone_id
            ? String(contentPage.service_zone_id)
            : '',
        promotion_id: contentPage?.promotion_id
            ? String(contentPage.promotion_id)
            : '',
        sort_order: contentPage ? String(contentPage.sort_order) : '0',
    });

    const isLocationPage = form.data.type === 'location_page';
    const isPromoLanding = form.data.type === 'promo_landing';

    const submit = () => {
        const payload = {
            type: form.data.type,
            title: form.data.title,
            slug: form.data.slug,
            excerpt: form.data.excerpt || null,
            body: form.data.body,
            featured_image_path: form.data.featured_image_path || null,
            meta_title: form.data.meta_title || null,
            meta_description: form.data.meta_description || null,
            og_image_path: form.data.og_image_path || null,
            category: form.data.category || null,
            status: form.data.status,
            published_at: form.data.published_at || null,
            // Type-conditional fields — see the Phase 6 task brief:
            // `service_zone_id` only means anything for `location_page`,
            // `promotion_id` only for `promo_landing`. Nulled out here
            // rather than left stale when the type changes away from the
            // one it applied to.
            service_zone_id: isLocationPage
                ? form.data.service_zone_id || null
                : null,
            promotion_id: isPromoLanding
                ? form.data.promotion_id || null
                : null,
            sort_order: Number(form.data.sort_order) || 0,
        };

        form.transform(() => payload);

        const options = {
            preserveScroll: true,
            onSuccess: () => {
                setOpen(false);
                if (!isEdit) {
                    form.reset();
                }
            },
        };

        if (isEdit) {
            form.put(ContentPageController.update(contentPage.id).url, options);
        } else {
            form.post(ContentPageController.store().url, options);
        }
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button
                    variant={isEdit ? 'outline' : 'default'}
                    size={isEdit ? 'sm' : 'default'}
                >
                    {isEdit ? 'Edit' : 'New page'}
                </Button>
            </DialogTrigger>
            <DialogContent className="max-h-[85vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>
                        {isEdit
                            ? `Edit ${contentPage.title}`
                            : 'New content page'}
                    </DialogTitle>
                </DialogHeader>

                <div className="grid gap-4">
                    <div className="grid grid-cols-2 gap-4">
                        <div className="grid gap-2">
                            <Label htmlFor="type">Type</Label>
                            <Select
                                value={form.data.type}
                                onValueChange={(v) =>
                                    form.setData('type', v as ContentPageType)
                                }
                            >
                                <SelectTrigger id="type" className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {CONTENT_PAGE_TYPE_OPTIONS.map((o) => (
                                        <SelectItem
                                            key={o.value}
                                            value={o.value}
                                        >
                                            {o.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.type} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="category">
                                Category (optional)
                            </Label>
                            <Input
                                id="category"
                                value={form.data.category}
                                onChange={(e) =>
                                    form.setData('category', e.target.value)
                                }
                            />
                            <InputError message={form.errors.category} />
                        </div>
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="title">Title</Label>
                        <Input
                            id="title"
                            value={form.data.title}
                            onChange={(e) =>
                                form.setData('title', e.target.value)
                            }
                        />
                        <InputError message={form.errors.title} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="slug">Slug</Label>
                        <Input
                            id="slug"
                            value={form.data.slug}
                            onChange={(e) =>
                                form.setData('slug', e.target.value)
                            }
                        />
                        <InputError message={form.errors.slug} />
                        <p className="text-muted-foreground text-xs">
                            Unique per type, not globally — two different types
                            may reuse the same slug.
                        </p>
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="excerpt">Excerpt (optional)</Label>
                        <Textarea
                            id="excerpt"
                            rows={2}
                            value={form.data.excerpt}
                            onChange={(e) =>
                                form.setData('excerpt', e.target.value)
                            }
                        />
                        <InputError message={form.errors.excerpt} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="body">Body</Label>
                        <Textarea
                            id="body"
                            rows={10}
                            value={form.data.body}
                            onChange={(e) =>
                                form.setData('body', e.target.value)
                            }
                        />
                        <InputError message={form.errors.body} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="featured_image_path">
                            Featured image URL / path (optional)
                        </Label>
                        <Input
                            id="featured_image_path"
                            placeholder="https://…"
                            value={form.data.featured_image_path}
                            onChange={(e) =>
                                form.setData(
                                    'featured_image_path',
                                    e.target.value,
                                )
                            }
                        />
                        <InputError message={form.errors.featured_image_path} />
                    </div>

                    <div className="rounded-md border border-dashed p-3">
                        <p className="mb-3 text-sm font-medium">
                            SEO overrides (optional — falls back to
                            title/excerpt/featured image)
                        </p>
                        <div className="grid gap-4">
                            <div className="grid gap-2">
                                <Label htmlFor="meta_title">Meta title</Label>
                                <Input
                                    id="meta_title"
                                    value={form.data.meta_title}
                                    onChange={(e) =>
                                        form.setData(
                                            'meta_title',
                                            e.target.value,
                                        )
                                    }
                                />
                                <InputError message={form.errors.meta_title} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="meta_description">
                                    Meta description
                                </Label>
                                <Textarea
                                    id="meta_description"
                                    rows={2}
                                    value={form.data.meta_description}
                                    onChange={(e) =>
                                        form.setData(
                                            'meta_description',
                                            e.target.value,
                                        )
                                    }
                                />
                                <InputError
                                    message={form.errors.meta_description}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="og_image_path">
                                    Open Graph image URL / path
                                </Label>
                                <Input
                                    id="og_image_path"
                                    placeholder="https://…"
                                    value={form.data.og_image_path}
                                    onChange={(e) =>
                                        form.setData(
                                            'og_image_path',
                                            e.target.value,
                                        )
                                    }
                                />
                                <InputError
                                    message={form.errors.og_image_path}
                                />
                            </div>
                        </div>
                    </div>

                    {isLocationPage && (
                        <div className="grid gap-2">
                            <Label htmlFor="service_zone_id">
                                Service zone (optional)
                            </Label>
                            <Select
                                value={form.data.service_zone_id || 'none'}
                                onValueChange={(v) =>
                                    form.setData(
                                        'service_zone_id',
                                        v === 'none' ? '' : v,
                                    )
                                }
                            >
                                <SelectTrigger
                                    id="service_zone_id"
                                    className="w-full"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="none">
                                        No linked zone
                                    </SelectItem>
                                    {serviceZones.map((zone) => (
                                        <SelectItem
                                            key={zone.id}
                                            value={String(zone.id)}
                                        >
                                            {zone.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.service_zone_id} />
                            <p className="text-muted-foreground text-xs">
                                Only meaningful for location pages — enables a
                                live-data widget, not required.
                            </p>
                        </div>
                    )}

                    {isPromoLanding && (
                        <div className="grid gap-2">
                            <Label htmlFor="promotion_id">
                                Promotion (optional)
                            </Label>
                            <Select
                                value={form.data.promotion_id || 'none'}
                                onValueChange={(v) =>
                                    form.setData(
                                        'promotion_id',
                                        v === 'none' ? '' : v,
                                    )
                                }
                            >
                                <SelectTrigger
                                    id="promotion_id"
                                    className="w-full"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="none">
                                        No linked promotion
                                    </SelectItem>
                                    {promotions.map((promotion) => (
                                        <SelectItem
                                            key={promotion.id}
                                            value={String(promotion.id)}
                                        >
                                            {promotion.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.promotion_id} />
                            <p className="text-muted-foreground text-xs">
                                Only meaningful for promo landing pages —
                                optional, may also be general campaign copy with
                                no single linked promotion.
                            </p>
                        </div>
                    )}

                    <div className="grid grid-cols-3 gap-4">
                        <div className="grid gap-2">
                            <Label htmlFor="status">Status</Label>
                            <Select
                                value={form.data.status}
                                onValueChange={(v) =>
                                    form.setData('status', v as PageStatus)
                                }
                            >
                                <SelectTrigger id="status" className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {PAGE_STATUS_OPTIONS.map((o) => (
                                        <SelectItem
                                            key={o.value}
                                            value={o.value}
                                        >
                                            {o.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.status} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="published_at">
                                Publish date (optional)
                            </Label>
                            <Input
                                id="published_at"
                                type="date"
                                value={form.data.published_at}
                                onChange={(e) =>
                                    form.setData('published_at', e.target.value)
                                }
                            />
                            <InputError message={form.errors.published_at} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="sort_order">Sort order</Label>
                            <Input
                                id="sort_order"
                                type="number"
                                min={0}
                                value={form.data.sort_order}
                                onChange={(e) =>
                                    form.setData('sort_order', e.target.value)
                                }
                            />
                            <InputError message={form.errors.sort_order} />
                        </div>
                    </div>
                    <p className="text-muted-foreground -mt-2 text-xs">
                        A draft is never publicly visible regardless of publish
                        date; a published page with a future publish date is
                        scheduled, not yet live.
                    </p>

                    {isEdit ? (
                        <PageFaqSection
                            contentPage={contentPage}
                            contentPages={contentPages}
                        />
                    ) : (
                        <p className="text-muted-foreground text-xs">
                            Save the page first, then reopen it here to manage
                            its own scoped FAQs.
                        </p>
                    )}
                </div>

                <DialogFooter>
                    <Button disabled={form.processing} onClick={submit}>
                        {isEdit ? 'Save changes' : 'Create page'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function DeleteContentPageButton({
    contentPage,
}: {
    contentPage: ContentPage;
}) {
    const destroy = () => {
        if (
            !confirm(
                `Delete "${contentPage.title}"? Its own scoped FAQs will be deleted too.`,
            )
        ) {
            return;
        }

        router.delete(ContentPageController.destroy(contentPage.id).url, {
            preserveScroll: true,
        });
    };

    return (
        <Button variant="outline" size="sm" onClick={destroy}>
            Delete
        </Button>
    );
}

export default function ContentPagesIndex({
    contentPages,
    serviceZones,
    promotions,
}: {
    contentPages: ContentPage[];
    serviceZones: { id: number; name: string }[];
    promotions: { id: number; name: string }[];
}) {
    const contentPageOptions = contentPages.map((p) => ({
        id: p.id,
        title: p.title,
        type: p.type,
    }));

    const list = useListFilter(contentPages, {
        placeholder: 'Search pages by title, slug or category…',
        searchText: (i) => [i.title, i.slug, i.category, i.excerpt],
        filters: {
            Type: { label: 'Type', get: (i) => i.type },
            Status: { label: 'Status', get: (i) => i.status },
        },
    });

    return (
        <>
            <Head title="Content pages" />

            <div className="flex justify-end">
                <Can permission="content.manage">
                    <ContentPageFormDialog
                        serviceZones={serviceZones}
                        promotions={promotions}
                        contentPages={contentPageOptions}
                    />
                </Can>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>Pages</CardTitle>
                    <CardDescription>
                        Blog posts, guides, location pages, promo landing copy,
                        and plain static pages — one type-discriminated table,
                        one screen.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <ListToolbar {...list.toolbarProps} />
                    {contentPages.length === 0 ? (
                        <p className="text-muted-foreground text-sm">
                            No content pages yet.
                        </p>
                    ) : (
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b text-left">
                                    <th className="py-2 font-medium">Title</th>
                                    <th className="py-2 font-medium">Type</th>
                                    <th className="py-2 font-medium">
                                        Category
                                    </th>
                                    <th className="py-2 font-medium">
                                        Linked to
                                    </th>
                                    <th className="py-2 font-medium">Status</th>
                                    <th className="py-2 font-medium">
                                        Published
                                    </th>
                                    <th className="py-2 font-medium" />
                                </tr>
                            </thead>
                            <tbody>
                                {list.filtered.map((page) => (
                                    <tr
                                        key={page.id}
                                        className="border-b last:border-0"
                                    >
                                        <td className="py-2 font-medium">
                                            {page.title}
                                            <div className="text-muted-foreground text-xs">
                                                /{page.slug}
                                            </div>
                                        </td>
                                        <td className="py-2">
                                            {CONTENT_PAGE_TYPE_LABELS[
                                                page.type
                                            ] ?? page.type}
                                        </td>
                                        <td className="text-muted-foreground py-2">
                                            {page.category ?? '—'}
                                        </td>
                                        <td className="text-muted-foreground py-2">
                                            {page.service_zone?.name ??
                                                page.promotion?.name ??
                                                '—'}
                                        </td>
                                        <td className="py-2">
                                            <Badge
                                                variant={pageStatusBadgeVariant(
                                                    page.status,
                                                )}
                                            >
                                                {page.status}
                                            </Badge>
                                        </td>
                                        <td className="text-muted-foreground py-2">
                                            {page.published_at
                                                ? new Date(
                                                      page.published_at,
                                                  ).toLocaleDateString('en-AU')
                                                : '—'}
                                        </td>
                                        <td className="space-x-2 py-2 text-right whitespace-nowrap">
                                            <Can permission="content.manage">
                                                <ContentPageFormDialog
                                                    contentPage={page}
                                                    serviceZones={serviceZones}
                                                    promotions={promotions}
                                                    contentPages={
                                                        contentPageOptions
                                                    }
                                                />
                                                <DeleteContentPageButton
                                                    contentPage={page}
                                                />
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

ContentPagesIndex.layout = {
    breadcrumbs: [
        { title: 'Content', href: ContentPageController.index().url },
        { title: 'Pages', href: ContentPageController.index().url },
    ] satisfies BreadcrumbItem[],
};
