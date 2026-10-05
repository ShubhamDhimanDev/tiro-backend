import { Link } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import ReportingController from '@/actions/App/Http/Controllers/Admin/Reporting/ReportingController';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { cn } from '@/lib/utils';
import type { ReportingDashboard } from '@/types/reporting';

const tabs: { title: string; dashboard: ReportingDashboard }[] = [
    { title: 'Sales', dashboard: 'sales' },
    { title: 'Bookings', dashboard: 'bookings' },
    { title: 'Hold → Order Conversion', dashboard: 'conversion' },
    { title: 'Cancellations', dashboard: 'cancellation' },
    { title: 'Product Performance', dashboard: 'product-performance' },
];

/**
 * Shared horizontal tab nav across the 5 reporting dashboards — same pattern
 * as `LocationsLayout`/`PromotionsLayout`/`VehiclesLayout`. All 5 share the
 * single `GET /admin/reporting/{dashboard}` route (see
 * `ReportingController::show()`), gated `reporting.view`.
 */
export default function ReportingLayout({ children }: PropsWithChildren) {
    const { isCurrentUrl } = useCurrentUrl();

    return (
        <div className="space-y-6 p-4">
            <Heading
                title="Reporting"
                description="Live-query dashboards — no materialized/cached tables, every figure reflects the current data as of page load."
            />

            <nav
                className="flex flex-wrap gap-1 border-b"
                aria-label="Reporting"
            >
                {tabs.map((tab) => {
                    const href = ReportingController.show(tab.dashboard).url;
                    return (
                        <Button
                            key={tab.dashboard}
                            variant="ghost"
                            asChild
                            className={cn(
                                'rounded-b-none border-b-2 border-transparent',
                                isCurrentUrl(href) && 'border-primary',
                            )}
                        >
                            <Link href={href}>{tab.title}</Link>
                        </Button>
                    );
                })}
            </nav>

            {children}
        </div>
    );
}
