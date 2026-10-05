<?php

namespace App\Enums;

/**
 * `ContentPage.type` — the single type-discriminator for every flavor of
 * admin-authored static content (blog/guide/location-page/promo-landing/
 * plain page all share one table/shape, not five separate tables) — see the
 * Phase 6 task brief's "one type-discriminated entity" note. Every `match`
 * over this enum elsewhere in the app must carry an explicit
 * `default => throw` arm (this project's exhaustive-enum convention — see
 * e.g. `App\Enums\VehicleFitmentConfidence`) so a future case added here
 * can't silently fall through unhandled logic.
 */
enum ContentPageType: string
{
    /** A plain static page (e.g. "About Us", "Terms of Service"). */
    case Page = 'page';

    /** A blog article, grouped/filterable by `ContentPage.category`. */
    case BlogPost = 'blog_post';

    /** A how-to/buying guide, same category posture as `BlogPost`. */
    case Guide = 'guide';

    /** Static authored copy for one suburb/zone, optionally linked to a `ServiceZone`. */
    case LocationPage = 'location_page';

    /** Static authored copy for a promo campaign, optionally linked to a `Promotion`. */
    case PromoLanding = 'promo_landing';
}
