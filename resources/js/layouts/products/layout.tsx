import { Link } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import BrandController from '@/actions/App/Http/Controllers/Admin/Products/BrandController';
import CatalogImportController from '@/actions/App/Http/Controllers/Admin/Products/CatalogImportController';
import { Can } from '@/components/can';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { cn } from '@/lib/utils';

/**
 * Shared horizontal tab nav across the Products screens (Brand -> TyreModel
 * -> TyreVariant drill-down, bulk WooCommerce CSV/JSON import) — same
 * pattern as `VehiclesLayout`. The bulk-import tab is hidden for
 * `products.view`-only users (Ecommerce, Customer Support) since that
 * screen has no meaningful read-only mode — this is UX only, the route
 * itself is independently gated on `products.manage`.
 */
export default function ProductsLayout({ children }: PropsWithChildren) {
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
                title="Products"
                description="Brand -> tyre model -> size variant catalog, and bulk supplier catalog import."
            />

            <nav className="flex gap-1 border-b" aria-label="Products">
                {tab('Brands', BrandController.index().url)}
                <Can permission="products.manage">
                    {tab('Bulk import', CatalogImportController.index().url)}
                </Can>
            </nav>

            {children}
        </div>
    );
}
