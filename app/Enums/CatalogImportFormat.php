<?php

namespace App\Enums;

/**
 * Column layout of a bulk catalogue import file.
 */
enum CatalogImportFormat: string
{
    /** Raw WooCommerce product export (Name, Brands, numbered Attribute columns, ...). */
    case Woocommerce = 'woocommerce';

    /**
     * One flat row per size variant: Brand, Model, Category, Tyre type, SKU,
     * Size, Load index, Speed rating, Base price ($), Status — the same
     * layout the admin "Export products" screen produces with its default
     * columns, so an export can be edited and re-imported.
     */
    case Simple = 'simple';
}
