import { Link } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import ContentPageController from '@/actions/App/Http/Controllers/Admin/Content/ContentPageController';
import FaqController from '@/actions/App/Http/Controllers/Admin/Content/FaqController';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { cn } from '@/lib/utils';

const tabs = [
    { title: 'Pages', href: ContentPageController.index().url },
    { title: 'FAQs', href: FaqController.index().url },
];

/**
 * Shared horizontal tab nav across the two Content screens (ContentPage CRUD,
 * standalone Faq management) — same pattern as `LocationsLayout`/
 * `PromotionsLayout`/`VehiclesLayout`. Both routes are gated `content.view`
 * for reads; mutation controls within each screen are further gated
 * `content.manage` via `<Can>`.
 */
export default function ContentLayout({ children }: PropsWithChildren) {
    const { isCurrentUrl } = useCurrentUrl();

    return (
        <div className="space-y-6 p-4">
            <Heading
                title="Content"
                description="Blog posts, guides, location pages, promo landing copy, plain pages, and FAQs."
            />

            <nav className="flex gap-1 border-b" aria-label="Content">
                {tabs.map((tab) => (
                    <Button
                        key={tab.href}
                        variant="ghost"
                        asChild
                        className={cn(
                            'rounded-b-none border-b-2 border-transparent',
                            isCurrentUrl(tab.href) && 'border-primary',
                        )}
                    >
                        <Link href={tab.href}>{tab.title}</Link>
                    </Button>
                ))}
            </nav>

            {children}
        </div>
    );
}
