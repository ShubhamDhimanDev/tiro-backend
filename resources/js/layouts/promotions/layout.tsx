import { Link } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import PriceGuaranteeClaimController from '@/actions/App/Http/Controllers/Admin/Promotions/PriceGuaranteeClaimController';
import PriceRuleController from '@/actions/App/Http/Controllers/Admin/Promotions/PriceRuleController';
import PromotionController from '@/actions/App/Http/Controllers/Admin/Promotions/PromotionController';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { cn } from '@/lib/utils';

const tabs = [
    { title: 'Campaigns', href: PromotionController.index().url },
    {
        title: 'Price-guarantee claims',
        href: PriceGuaranteeClaimController.index().url,
    },
    { title: 'Price rules', href: PriceRuleController.index().url },
];

/**
 * Shared horizontal tab nav across the three Promotions screens (campaign
 * CRUD, price-guarantee claim review, per-zone PriceRule CRUD) — same
 * pattern as `LocationsLayout`/`VehiclesLayout`.
 *
 * **Updated 2026-09-23 (Phase 6 RBAC hardening pass):** the three `index`
 * routes below this layout are now gated `promotions.view`, not
 * `promotions.manage` (see `routes/admin.php`'s Promotions section for the
 * backend-agent fix this corrects for) — a `promotions.view`-only
 * Operations/Customer Support user now reaches all three tabs read-only. No
 * per-tab `<Can>` hiding is needed *here* regardless, since every
 * create/edit/delete control lives inside each tab's own screen component
 * (already gated `<Can permission="promotions.manage">` there, e.g.
 * `PromotionsCampaignsIndex`) — this layout only renders the tab nav itself,
 * which is safe to show to any visitor who already reached the route.
 */
export default function PromotionsLayout({ children }: PropsWithChildren) {
    const { isCurrentUrl } = useCurrentUrl();

    return (
        <div className="space-y-6 p-4">
            <Heading
                title="Promotions"
                description="Campaign creation, eligibility, price-guarantee claim review, and per-zone service-fee rules."
            />

            <nav className="flex gap-1 border-b" aria-label="Promotions">
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
