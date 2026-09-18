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
