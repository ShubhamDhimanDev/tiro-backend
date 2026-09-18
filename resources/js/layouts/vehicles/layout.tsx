import { Link } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import VehicleController from '@/actions/App/Http/Controllers/Admin/Vehicles/VehicleController';
import VehicleFitmentImportController from '@/actions/App/Http/Controllers/Admin/Vehicles/VehicleFitmentImportController';
import { Can } from '@/components/can';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { cn } from '@/lib/utils';

/**
 * Shared horizontal tab nav across the two Vehicles screens (row-level CRUD,
 * bulk CSV/JSON import) — same pattern as `LocationsLayout`. The bulk-import
 * tab is hidden for `vehicles.view`-only users (Ecommerce, Customer
 * Support) since that screen has no meaningful read-only mode — this is UX
 * only, the route itself is independently gated on `vehicles.manage`.
 */
export default function VehiclesLayout({ children }: PropsWithChildren) {
    const { isCurrentUrl } = useCurrentUrl();

    const tab = (title: string, href: string) => (
        <Button
            key={href}
            variant="ghost"
            asChild
            className={cn(
                'rounded-b-none border-b-2 border-transparent',
                isCurrentUrl(href) && 'border-primary',
            )}
        >
            <Link href={href}>{title}</Link>
        </Button>
    );

    return (
        <div className="space-y-6 p-4">
            <Heading
                title="Vehicles"
                description="Make/model/generation entries and their OE tyre fitment sizes — drives the storefront's manual vehicle picker."
            />

            <nav className="flex gap-1 border-b" aria-label="Vehicles">
                {tab('Vehicles', VehicleController.index().url)}
                <Can permission="vehicles.manage">
                    {tab(
                        'Bulk import',
                        VehicleFitmentImportController.index().url,
                    )}
                </Can>
            </nav>

            {children}
        </div>
    );
}
