import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import FaqController from '@/actions/App/Http/Controllers/Admin/Content/FaqController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
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
import { PAGE_STATUS_OPTIONS } from '@/lib/enums';
import type { ContentPageSummary, Faq, PageStatus } from '@/types/content';

const GLOBAL_VALUE = 'global';

type FaqFormData = {
    question: string;
    answer: string;
    category: string;
    content_page_id: string;
    sort_order: string;
    status: PageStatus;
};

/**
 * Create/edit dialog for a single {@see Faq} row — shared by the standalone
 * FAQ management screen (`content/faqs/index.tsx`, where the scope dropdown
 * is visible so an admin can pick "Global" or any page) and
 * `content/pages/index.tsx`'s nested per-page FAQ editor (where
 * `fixedContentPageId` locks the scope to that page and hides the dropdown
 * entirely — no way to accidentally re-scope a page's own FAQ to global or
 * another page from within that page's own editor).
 */
export function FaqFormDialog({
    faq,
    contentPages,
    fixedContentPageId,
    trigger,
}: {
    faq?: Faq;
    contentPages: ContentPageSummary[];
    fixedContentPageId?: number;
    trigger?: ReactNode;
}) {
    const isEdit = !!faq;
    const [open, setOpen] = useState(false);

    const form = useForm<FaqFormData>({
        question: faq?.question ?? '',
        answer: faq?.answer ?? '',
        category: faq?.category ?? '',
        content_page_id:
            fixedContentPageId !== undefined
                ? String(fixedContentPageId)
                : faq?.content_page_id
                  ? String(faq.content_page_id)
                  : GLOBAL_VALUE,
        sort_order: faq ? String(faq.sort_order) : '0',
        status: faq?.status ?? 'published',
    });

    const submit = () => {
        const payload = {
            question: form.data.question,
            answer: form.data.answer,
            category: form.data.category || null,
            content_page_id:
                form.data.content_page_id === GLOBAL_VALUE
                    ? null
                    : Number(form.data.content_page_id),
            sort_order: Number(form.data.sort_order) || 0,
            status: form.data.status,
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
            form.put(FaqController.update(faq.id).url, options);
        } else {
            form.post(FaqController.store().url, options);
        }
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                {trigger ?? (
                    <Button
                        variant={isEdit ? 'outline' : 'default'}
                        size={isEdit ? 'sm' : 'default'}
                    >
                        {isEdit ? 'Edit' : 'New FAQ'}
                    </Button>
                )}
            </DialogTrigger>
            <DialogContent className="max-h-[85vh] overflow-y-auto sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>{isEdit ? 'Edit FAQ' : 'New FAQ'}</DialogTitle>
                </DialogHeader>

                <div className="grid gap-4">
                    <div className="grid gap-2">
                        <Label htmlFor="question">Question</Label>
                        <Textarea
                            id="question"
                            rows={2}
                            value={form.data.question}
                            onChange={(e) =>
                                form.setData('question', e.target.value)
                            }
                        />
                        <InputError message={form.errors.question} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="answer">Answer</Label>
                        <Textarea
                            id="answer"
                            rows={5}
                            value={form.data.answer}
                            onChange={(e) =>
                                form.setData('answer', e.target.value)
                            }
                        />
                        <InputError message={form.errors.answer} />
                    </div>

                    <div className="grid grid-cols-2 gap-4">
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
                            <p className="text-muted-foreground text-xs">
                                "pdp" is reserved — the PDP's shared FAQ block
                                filters on it.
                            </p>
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

                    {fixedContentPageId === undefined && (
                        <div className="grid gap-2">
                            <Label htmlFor="content_page_id">Scope</Label>
                            <Select
                                value={form.data.content_page_id}
                                onValueChange={(v) =>
                                    form.setData('content_page_id', v)
                                }
                            >
                                <SelectTrigger
                                    id="content_page_id"
                                    className="w-full"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={GLOBAL_VALUE}>
                                        Global (no page)
                                    </SelectItem>
                                    {contentPages.map((page) => (
                                        <SelectItem
                                            key={page.id}
                                            value={String(page.id)}
                                        >
                                            {page.title}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.content_page_id} />
                        </div>
                    )}

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
                                    <SelectItem key={o.value} value={o.value}>
                                        {o.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={form.errors.status} />
                    </div>
                </div>

                <DialogFooter>
                    <Button disabled={form.processing} onClick={submit}>
                        {isEdit ? 'Save changes' : 'Create FAQ'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
