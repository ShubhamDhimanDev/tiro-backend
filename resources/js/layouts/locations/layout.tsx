import { Link } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import ServiceZoneController from '@/actions/App/Http/Controllers/Admin/Locations/ServiceZoneController';
import StateController from '@/actions/App/Http/Controllers/Admin/Locations/StateController';
import SuburbController from '@/actions/App/Http/Controllers/Admin/Locations/SuburbController';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { cn } from '@/lib/utils';

const tabs = [
    { title: 'States', href: StateController.index().url },
    { title: 'Service zones', href: ServiceZoneController.index().url },
    { title: 'Suburbs', href: SuburbController.index().url },
];

/**
 * Shared horizontal tab nav across the three Locations screens (States,
 * Service zones, Suburbs) — all admin-managed geography that drives
 * serviceability resolution, kept one page-switch apart.
 */
export default function LocationsLayout({ children }: PropsWithChildren) {
    const { isCurrentUrl } = useCurrentUrl();

    return (
        <div className="space-y-6 p-4">
            <Heading
                title="Locations"
                description="States, service zones, and suburbs — fully admin-managed, not seeded/fixed data."
            />

            <nav className="flex gap-1 border-b" aria-label="Locations">
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
