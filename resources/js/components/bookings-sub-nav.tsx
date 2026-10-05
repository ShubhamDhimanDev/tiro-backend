import { Link } from '@inertiajs/react';
import CancellationPolicyController from '@/actions/App/Http/Controllers/Admin/Bookings/CancellationPolicyController';
import TechnicianController from '@/actions/App/Http/Controllers/Admin/Bookings/TechnicianController';
import TechnicianShiftController from '@/actions/App/Http/Controllers/Admin/Bookings/TechnicianShiftController';
import VanController from '@/actions/App/Http/Controllers/Admin/Bookings/VanController';
import DispatchBoardController from '@/actions/App/Http/Controllers/Admin/Bookings/DispatchBoardController';
import { cn } from '@/lib/utils';

/**
 * Lateral navigation across the Bookings module's 5 screens (dispatch board
 * + the 4 roster/config CRUD screens beneath it) — the sidebar only ever
 * links to the dispatch board (see `app-sidebar.tsx`, same drill-down
 * pattern as Products: brand -> models -> variants), so these screens need
 * their own way to cross-link to each other.
 */
export function BookingsSubNav({
    active,
}: {
    active:
        | 'dispatch'
        | 'vans'
        | 'technicians'
        | 'shifts'
        | 'cancellation-policies';
}) {
    const items = [
        {
            key: 'dispatch',
            label: 'Dispatch board',
            href: DispatchBoardController.index().url,
        },
        { key: 'vans', label: 'Vans', href: VanController.index().url },
        {
            key: 'technicians',
            label: 'Technicians',
            href: TechnicianController.index().url,
        },
        {
            key: 'shifts',
            label: 'Shifts',
            href: TechnicianShiftController.index().url,
        },
        {
            key: 'cancellation-policies',
            label: 'Cancellation policies',
            href: CancellationPolicyController.index().url,
        },
    ] as const;

    return (
        <nav className="flex flex-wrap gap-1 border-b pb-2">
            {items.map((item) => (
                <Link
                    key={item.key}
                    href={item.href}
                    className={cn(
                        'rounded-md px-3 py-1.5 text-sm transition-colors',
                        item.key === active
                            ? 'bg-primary text-primary-foreground'
                            : 'text-muted-foreground hover:bg-accent hover:text-accent-foreground',
                    )}
                >
                    {item.label}
                </Link>
            ))}
        </nav>
    );
}
