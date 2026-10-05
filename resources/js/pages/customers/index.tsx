import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import CustomerController from '@/actions/App/Http/Controllers/Admin/Customers/CustomerController';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { CustomerFilters, CustomerListRow } from '@/types/customers';
import type { Paginated } from '@/types/orders';
import type { BreadcrumbItem } from '@/types';

export default function CustomersIndex({
    filters,
    customers,
}: {
    filters: CustomerFilters;
    customers: Paginated<CustomerListRow>;
}) {
    const [searchInput, setSearchInput] = useState(filters.search ?? '');

    const submitSearch = (e: FormEvent) => {
        e.preventDefault();
        router.get(
            CustomerController.index().url,
            { search: searchInput || null },
            { preserveState: true, preserveScroll: true },
        );
    };

    const goToPage = (page: number) => {
        router.get(
            CustomerController.index().url,
            { ...filters, page },
            { preserveState: true, preserveScroll: true },
        );
    };

    return (
        <>
            <Head title="Customers" />

            <div className="space-y-6 p-4">
                <Heading
                    title="Customers"
                    description="Search customer accounts by name, email, or mobile."
                />

                <Card>
                    <CardContent className="flex flex-wrap items-end gap-4 pt-6">
                        <form onSubmit={submitSearch} className="grid gap-2">
                            <Label htmlFor="filter-search">
                                Name, email, or mobile
                            </Label>
                            <div className="flex gap-2">
                                <Input
                                    id="filter-search"
                                    className="w-72"
                                    placeholder="e.g. jane@example.com"
                                    value={searchInput}
                                    onChange={(e) =>
                                        setSearchInput(e.target.value)
                                    }
                                />
                                <Button type="submit" variant="outline">
                                    Search
                                </Button>
                            </div>
                        </form>

                        {filters.search && (
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() => {
                                    setSearchInput('');
                                    router.get(CustomerController.index().url);
                                }}
                            >
                                Clear filters
                            </Button>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardContent className="pt-6">
                        {customers.data.length === 0 ? (
                            <p className="text-muted-foreground text-sm">
                                No customers match this filter.
                            </p>
                        ) : (
                            <>
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b text-left">
                                            <th className="py-2 font-medium">
                                                Name
                                            </th>
                                            <th className="py-2 font-medium">
                                                Email
                                            </th>
                                            <th className="py-2 font-medium">
                                                Mobile
                                            </th>
                                            <th className="py-2 font-medium">
                                                Joined
                                            </th>
                                            <th className="py-2 font-medium" />
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {customers.data.map((customer) => (
                                            <tr
                                                key={customer.id}
                                                className="border-b last:border-0"
                                            >
                                                <td className="py-2 font-medium">
                                                    {customer.name}
                                                </td>
                                                <td className="text-muted-foreground py-2">
                                                    {customer.email}
                                                </td>
                                                <td className="text-muted-foreground py-2">
                                                    {customer.mobile ?? '—'}
                                                </td>
                                                <td className="text-muted-foreground py-2">
                                                    {new Date(
                                                        customer.created_at,
                                                    ).toLocaleDateString(
                                                        'en-AU',
                                                    )}
                                                </td>
                                                <td className="py-2 text-right">
                                                    <Button
                                                        asChild
                                                        variant="outline"
                                                        size="sm"
                                                    >
                                                        <Link
                                                            href={
                                                                CustomerController.show(
                                                                    customer.id,
                                                                ).url
                                                            }
                                                        >
                                                            View
                                                        </Link>
                                                    </Button>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>

                                <div className="text-muted-foreground flex items-center justify-between pt-4 text-sm">
                                    <span>
                                        Showing {customers.from ?? 0}–
                                        {customers.to ?? 0} of {customers.total}
                                    </span>
                                    <div className="flex gap-2">
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            disabled={
                                                customers.current_page <= 1
                                            }
                                            onClick={() =>
                                                goToPage(
                                                    customers.current_page - 1,
                                                )
                                            }
                                        >
                                            Previous
                                        </Button>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            disabled={
                                                customers.current_page >=
                                                customers.last_page
                                            }
                                            onClick={() =>
                                                goToPage(
                                                    customers.current_page + 1,
                                                )
                                            }
                                        >
                                            Next
                                        </Button>
                                    </div>
                                </div>
                            </>
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

CustomersIndex.layout = {
    breadcrumbs: [
        { title: 'Customers', href: CustomerController.index().url },
    ] satisfies BreadcrumbItem[],
};
