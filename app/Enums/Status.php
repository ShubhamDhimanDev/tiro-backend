<?php

namespace App\Enums;

/**
 * Shared admin lifecycle/visibility status, used consistently across every
 * catalogue and location entity that carries a `status` column (Brand,
 * TyreModel, TyreVariant, PopularSize, State, ServiceZone) so admin UIs and
 * the storefront API only ever deal with one status vocabulary.
 */
enum Status: string
{
    /** Being prepared/configured; not yet visible to the storefront. */
    case Draft = 'draft';

    /** Live and in normal use. */
    case Active = 'active';

    /** Temporarily disabled/hidden without deleting the record. */
    case Inactive = 'inactive';

    /** Permanently retired; kept for historical/reporting integrity. */
    case Archived = 'archived';
}
