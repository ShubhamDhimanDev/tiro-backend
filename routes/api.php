<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Bookings\BookingAvailabilityController;
use App\Http\Controllers\Api\V1\Bookings\BookingController;
use App\Http\Controllers\Api\V1\Bookings\BookingSlotController;
use App\Http\Controllers\Api\V1\Cart\CartController;
use App\Http\Controllers\Api\V1\Catalogue\BrandController;
use App\Http\Controllers\Api\V1\Catalogue\TyreController;
use App\Http\Controllers\Api\V1\Content\ContentPageController;
use App\Http\Controllers\Api\V1\Content\FaqController;
use App\Http\Controllers\Api\V1\Customer\AddressController as CustomerAddressController;
use App\Http\Controllers\Api\V1\Customer\OrderController as CustomerOrderController;
use App\Http\Controllers\Api\V1\Customer\VehicleController as CustomerVehicleController;
use App\Http\Controllers\Api\V1\Enquiries\EnquiryController;
use App\Http\Controllers\Api\V1\Location\CoverageController;
use App\Http\Controllers\Api\V1\Location\ServiceabilityController;
use App\Http\Controllers\Api\V1\Location\SuburbController;
use App\Http\Controllers\Api\V1\Newsletter\NewsletterSubscriptionController;
use App\Http\Controllers\Api\V1\Offers\OfferController;
use App\Http\Controllers\Api\V1\Orders\OrderController;
use App\Http\Controllers\Api\V1\PriceGuaranteeClaims\PriceGuaranteeClaimController;
use App\Http\Controllers\Api\V1\Reviews\ReviewController;
use App\Http\Controllers\Api\V1\SocialProof\RecentOrderController;
use App\Http\Controllers\Api\V1\Vehicles\VehicleController;
use App\Http\Controllers\Api\V1\Webhooks\PayPalWebhookController;
use App\Http\Controllers\Api\V1\Webhooks\StripeWebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/auth')->name('api.v1.auth.')->group(function () {
    Route::post('register', [AuthController::class, 'register'])
        ->middleware('throttle:otp-request-registration')
        ->name('register');

    Route::post('register/verify', [AuthController::class, 'verifyRegistration'])
        ->middleware('throttle:otp-verify-registration')
        ->name('register.verify');

    Route::post('login', [AuthController::class, 'login'])
        ->name('login');

    Route::post('otp/request', [AuthController::class, 'requestOtp'])
        ->middleware('throttle:otp-request-login')
        ->name('otp.request');

    Route::post('otp/verify', [AuthController::class, 'verifyOtp'])
        ->middleware('throttle:otp-verify-login')
        ->name('otp.verify');

    Route::post('password/reset/request', [AuthController::class, 'requestPasswordReset'])
        ->middleware('throttle:otp-request-password_reset')
        ->name('password.reset.request');

    Route::post('password/reset/verify', [AuthController::class, 'verifyPasswordReset'])
        ->middleware('throttle:otp-verify-password_reset')
        ->name('password.reset.verify');

    Route::middleware('auth:customer')->group(function () {
        Route::delete('session', [AuthController::class, 'destroySession'])->name('session.destroy');
        Route::delete('sessions', [AuthController::class, 'destroyAllSessions'])->name('sessions.destroy');
    });
});

Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::post('serviceability', [ServiceabilityController::class, 'check'])
        ->middleware('throttle:public-lookups')
        ->name('serviceability.check');

    // Resolves a Places-picked postcode + suburb name to the `Suburb.id`
    // `POST /api/v1/orders`'s `address.suburb_id` requires — see
    // docs/architecture/02-api-contract.md's "Zone resolution / overlap
    // rule" section and `SuburbController`'s own docblock.
    Route::get('suburbs', [SuburbController::class, 'index'])->name('suburbs.index');

    // Phase 7: fitting-location typeahead, week-strip availability and the
    // newsletter sign-up (public, throttled).
    Route::get('suburbs/search', [SuburbController::class, 'search'])
        ->middleware('throttle:public-lookups')
        ->name('suburbs.search');

    Route::get('booking-availability', [BookingAvailabilityController::class, 'index'])
        ->middleware('throttle:public-lookups')
        ->name('booking-availability');

    Route::post('newsletter-subscriptions', [NewsletterSubscriptionController::class, 'store'])
        ->middleware('throttle:newsletter')
        ->name('newsletter-subscriptions.store');

    // Order matters: the literal `popular-sizes`/`latest-releases` segments
    // must be registered before the `{slug}` wildcard routes, or they'd be
    // captured as a slug lookup instead.
    Route::prefix('tyres')->name('tyres.')->group(function () {
        Route::get('popular-sizes', [TyreController::class, 'popularSizes'])->name('popular-sizes');
        Route::get('latest-releases', [TyreController::class, 'latestReleases'])->name('latest-releases');
        Route::get('facets', [TyreController::class, 'facets'])->name('facets');
        Route::get('price-ladders', [TyreController::class, 'priceLadders'])
            ->middleware('throttle:public-lookups')
            ->name('price-ladders');
        Route::get('{slug}/availability', [TyreController::class, 'availability'])->name('availability');
        Route::get('{slug}', [TyreController::class, 'show'])->name('show');
        Route::get('/', [TyreController::class, 'index'])->name('index');
    });

    // Phase 6a public additions: offers, coverage tree, enquiry form.
    Route::get('offers', [OfferController::class, 'index'])->name('offers.index');
    Route::get('offers/{slug}', [OfferController::class, 'show'])->name('offers.show');

    Route::get('locations', [CoverageController::class, 'index'])->name('locations.index');
    Route::get('locations/{state}/{city}', [CoverageController::class, 'show'])->name('locations.show');

    Route::post('enquiries', [EnquiryController::class, 'store'])
        ->middleware('throttle:enquiries')
        ->name('enquiries.store');

    // Anonymised "recently purchased" social-proof feed — see
    // docs/architecture/02-api-contract.md's "Social proof: recent orders (R2)".
    Route::get('social-proof/recent-orders', [RecentOrderController::class, 'index'])
        ->middleware('throttle:public-lookups')
        ->name('social-proof.recent-orders');

    Route::get('brands', [BrandController::class, 'index'])->name('brands.index');
    Route::get('brands/{slug}', [BrandController::class, 'show'])->name('brands.show');

    // Order matters here too: the literal `makes`/`models`/`years` segments
    // must be registered before the `{vehicle}` wildcard route.
    Route::prefix('vehicles')->name('vehicles.')->group(function () {
        Route::get('makes', [VehicleController::class, 'makes'])->name('makes');
        Route::get('models', [VehicleController::class, 'models'])->name('models');
        Route::get('years', [VehicleController::class, 'years'])->name('years');
        Route::get('{vehicle}/fitment', [VehicleController::class, 'fitment'])->name('fitment');
    });

    // See docs/architecture/02-api-contract.md's "Booking & capacity
    // endpoints" section. `store`/`reschedule`/`cancel` deliberately do NOT
    // carry `auth:customer` middleware — that guard would reject guests
    // outright, and these routes work guest or authenticated (the
    // controller resolves `$request->user('customer')` on demand).
    Route::get('booking-slots', [BookingSlotController::class, 'index'])->name('booking-slots');

    Route::prefix('bookings')->name('bookings.')->group(function () {
        Route::post('/', [BookingController::class, 'store'])
            ->middleware(['throttle:checkout-writes', 'idempotency'])
            ->name('store');
        Route::get('{booking}', [BookingController::class, 'show'])->name('show');
        Route::patch('{booking}/reschedule', [BookingController::class, 'reschedule'])->name('reschedule');
        Route::post('{booking}/cancel', [BookingController::class, 'cancel'])->name('cancel');
    });

    // See docs/architecture/02-api-contract.md's "Cart, Checkout & Payment
    // endpoints" section. `cart/calculate` is a stateless pricing preview
    // (no row persisted, no Idempotency-Key needed); `orders` reuses the
    // same `idempotency` middleware as `bookings.store` above, unmodified.
    Route::post('cart/calculate', [CartController::class, 'calculate'])
        ->middleware('throttle:public-lookups')
        ->name('cart.calculate');

    Route::prefix('orders')->name('orders.')->group(function () {
        Route::post('/', [OrderController::class, 'store'])
            ->middleware(['throttle:checkout-writes', 'idempotency'])
            ->name('store');
        Route::get('{order}', [OrderController::class, 'show'])->name('show');

        // PayPal only — called by the frontend's PayPal Buttons' onApprove
        // callback right after the customer approves. Same auth as `show`
        // above (authenticated owner or X-Order-Token), same idempotency
        // convention as `store` above (a double-submit must not attempt a
        // second PayPal capture call). See
        // docs/architecture/02-api-contract.md's
        // `POST /api/v1/orders/{order}/paypal-capture` section.
        Route::post('{order}/paypal-capture', [OrderController::class, 'paypalCapture'])
            ->middleware('idempotency')
            ->name('paypal-capture');
    });

    // See docs/architecture/02-api-contract.md's "Promotions &
    // Price-Guarantee endpoints" section. `auth:customer`-only, no guest
    // path — see docs/architecture/05-promotions-pricing.md's
    // "Price-guarantee claim workflow" section. Deliberately no
    // `idempotency` middleware — a duplicate claim row from a double-submit
    // is an admin-queue nuisance, not a money bug.
    Route::middleware('auth:customer')->prefix('price-guarantee-claims')->name('price-guarantee-claims.')->group(function () {
        Route::post('/', [PriceGuaranteeClaimController::class, 'store'])->name('store');
        Route::get('/', [PriceGuaranteeClaimController::class, 'index'])->name('index');
    });

    // Public admin-authored CMS content (blog/guide/location-page/promo-
    // landing/plain page + FAQs) — Phase 6. `faqs` has no `{content_page}`
    // wildcard route: it's filter-param driven only, see FaqController.
    // Order matters here too: the literal `faqs` segment is a sibling of
    // `pages`, not nested under it (a page-scoped FAQ block is reached via
    // `?content_page_id=`, not a nested URL).
    Route::prefix('content')->name('content.')->group(function () {
        Route::get('faqs', [FaqController::class, 'index'])->name('faqs.index');

        Route::prefix('pages')->name('pages.')->group(function () {
            Route::get('/', [ContentPageController::class, 'index'])->name('index');
            Route::get('{type}/{slug}', [ContentPageController::class, 'show'])->name('show');
        });
    });

    // Customer account endpoints — Phase 7. `auth:customer`-only, no guest
    // path to any of these (see docs/architecture, Phase 7 readiness pass).
    // Ownership is enforced by scoping every query to the authenticated
    // `customer_id` (see App\Http\Controllers\Concerns\AuthorizesCustomerOwnership) —
    // a mismatched/nonexistent id is a plain 404, unlike bookings/orders'
    // separate guest-token 403 mechanism, which is unrelated and unchanged.
    Route::middleware('auth:customer')->prefix('customer')->name('customer.')->group(function () {
        Route::prefix('vehicles')->name('vehicles.')->group(function () {
            Route::get('/', [CustomerVehicleController::class, 'index'])->name('index');
            Route::post('/', [CustomerVehicleController::class, 'store'])->name('store');
            Route::patch('{vehicle}', [CustomerVehicleController::class, 'update'])->name('update');
            Route::delete('{vehicle}', [CustomerVehicleController::class, 'destroy'])->name('destroy');
            Route::post('{vehicle}/set-default', [CustomerVehicleController::class, 'setDefault'])->name('set-default');
        });

        Route::prefix('addresses')->name('addresses.')->group(function () {
            Route::get('/', [CustomerAddressController::class, 'index'])->name('index');
            Route::post('/', [CustomerAddressController::class, 'store'])->name('store');
            Route::patch('{address}', [CustomerAddressController::class, 'update'])->name('update');
            Route::delete('{address}', [CustomerAddressController::class, 'destroy'])->name('destroy');
            Route::post('{address}/set-default', [CustomerAddressController::class, 'setDefault'])->name('set-default');
        });

        // No separate `/customer/bookings` endpoint — a booking's summary
        // is nested per order; the detail view reuses the existing
        // `GET /api/v1/orders/{order}` unmodified.
        Route::get('orders', [CustomerOrderController::class, 'index'])->name('orders.index');
    });

    // Public, no auth — Phase 8. Every row excludes `is_hidden = true`
    // (see `App\Models\Review::scopeVisible()`); `meta.summary` is computed
    // over the full non-hidden set, not just the current page. See
    // `App\Http\Controllers\Api\V1\Reviews\ReviewController`.
    Route::get('reviews', [ReviewController::class, 'index'])->name('reviews.index');
});

// Stripe-facing, not storefront-facing — kept under `/api/v1/` for one
// consistent versioning convention project-wide. Deliberately excluded from
// the `v1` group's Idempotency-Key-guarded routes above (Stripe's caller
// sends neither an Idempotency-Key nor a Sanctum bearer token — signature
// verification via `Stripe\Webhook::constructEvent()` inside the controller
// is this route's actual trust boundary). See
// docs/architecture/02-api-contract.md's `POST /api/v1/webhooks/stripe`
// section.
Route::post('v1/webhooks/stripe', [StripeWebhookController::class, 'handle'])->name('api.v1.webhooks.stripe');

// PayPal-facing, not storefront-facing — same versioning/exclusion posture
// as the Stripe route above (PayPal's caller sends neither an
// Idempotency-Key nor a Sanctum bearer token — real server-to-server
// signature verification via PayPalWebhookSignatureVerifier is this route's
// actual trust boundary). See
// docs/architecture/02-api-contract.md's `POST /api/v1/webhooks/paypal`
// section.
Route::post('v1/webhooks/paypal', [PayPalWebhookController::class, 'handle'])->name('api.v1.webhooks.paypal');
