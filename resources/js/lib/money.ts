/**
 * `TyreVariant.base_price` is stored server-side in whole cents (see
 * TyreVariantRequest). These convert between that wire value and the
 * dollars-and-cents string an admin actually types.
 */
export function centsToDollarsInput(cents: number | null | undefined): string {
    if (cents === null || cents === undefined) {
        return '';
    }

    return (cents / 100).toFixed(2);
}

export function dollarsInputToCents(value: string): number {
    const dollars = Number.parseFloat(value);

    if (Number.isNaN(dollars)) {
        return 0;
    }

    return Math.round(dollars * 100);
}

/**
 * Display-only cents -> AUD formatter (e.g. `75600` -> `"$756.00"`). All
 * prices in this system are GST-inclusive — see
 * docs/architecture/01-data-model.md's "Money & tax convention" section —
 * so this is always the final, all-in figure, never something a caller
 * should add GST on top of.
 */
export function formatCents(cents: number): string {
    return new Intl.NumberFormat('en-AU', {
        style: 'currency',
        currency: 'AUD',
    }).format(cents / 100);
}
