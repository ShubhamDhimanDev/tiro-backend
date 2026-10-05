<?php

namespace App\Providers;

use App\Contracts\Notifications\SmsProvider;
use App\Contracts\Payments\PaymentGateway;
use App\Enums\PaymentGateway as PaymentGatewayEnum;
use App\Listeners\LogNotificationDelivery;
use App\Listeners\MarkStaffEmailAsVerifiedOnPasswordReset;
use App\Models\Booking;
use App\Models\Brand;
use App\Models\ContentPage;
use App\Models\Faq;
use App\Models\PriceRule;
use App\Models\Promotion;
use App\Models\TyreModel;
use App\Models\TyreVariant;
use App\Notifications\Channels\SmsChannel;
use App\Observers\BookingNotificationObserver;
use App\Observers\ContentPageObserver;
use App\Observers\FaqObserver;
use App\Observers\FrontendRevalidationObserver;
use App\Observers\PriceRuleObserver;
use App\Observers\PromotionObserver;
use App\Services\Notifications\MessageMediaClient;
use App\Services\Payments\PaymentGatewayResolver;
use App\Services\Payments\PayPalPaymentGateway;
use App\Services\Payments\StripePaymentGateway;
use App\Support\DevQueueGuard;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\Request;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use PaypalServerSdkLib\Authentication\ClientCredentialsAuthCredentialsBuilder;
use PaypalServerSdkLib\Environment as PayPalEnvironment;
use PaypalServerSdkLib\PaypalServerSdkClient;
use PaypalServerSdkLib\PaypalServerSdkClientBuilder;
use RuntimeException;
use Stripe\StripeClient;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Bound lazily — never constructed unless something actually
        // resolves `StripeClient`/`StripePaymentGateway`, so an empty
        // STRIPE_SECRET_KEY in an environment that never hits a payment
        // code path (or a test that binds a fake gateway instead) never has
        // to care. Both directions now — see
        // docs/architecture/03-integrations.md's PayPal section, point 2:
        // constructing `StripePaymentGateway::class` never constructs
        // `PayPalPaymentGateway::class` and vice versa, so the inactive
        // gateway's missing credentials never matter either.
        $this->app->singleton(StripeClient::class, fn (): StripeClient => new StripeClient((string) config('services.stripe.secret')));

        $this->app->singleton(
            StripePaymentGateway::class,
            fn (Application $app): StripePaymentGateway => new StripePaymentGateway($app->make(StripeClient::class)),
        );

        $this->app->singleton(PaypalServerSdkClient::class, fn (): PaypalServerSdkClient => PaypalServerSdkClientBuilder::init()
            ->environment(in_array(config('services.paypal.mode'), ['production', 'live'], true) ? PayPalEnvironment::PRODUCTION : PayPalEnvironment::SANDBOX)
            ->clientCredentialsAuthCredentials(ClientCredentialsAuthCredentialsBuilder::init(
                (string) config('services.paypal.client_id'),
                (string) config('services.paypal.client_secret'),
            ))
            ->build());

        $this->app->singleton(
            PayPalPaymentGateway::class,
            fn (Application $app): PayPalPaymentGateway => new PayPalPaymentGateway($app->make(PaypalServerSdkClient::class)),
        );

        // The **active-gateway-only** binding — correct exclusively for code
        // paths creating a brand-new payment (today:
        // `OrderController::store()`'s create branch and its
        // Idempotency-Key-replay branch — see that class's docblock).
        // Nothing else should depend on this interface; historical-payment
        // operations (refunds, webhook/reconciliation code touching an
        // already-persisted `Payment` row) must resolve via
        // `PaymentGatewayResolver` off that row's own `gateway` column
        // instead — see docs/architecture/03-integrations.md's PayPal
        // section, point 3.
        $this->app->singleton(PaymentGateway::class, function (Application $app): PaymentGateway {
            $configured = (string) config('services.payment_gateway');
            $gateway = PaymentGatewayEnum::tryFrom($configured);

            // Fail loud, not a silent fallback to Stripe — same
            // "throw on unmapped closed-vocabulary value" discipline this
            // codebase already applies everywhere else.
            return match ($gateway) {
                PaymentGatewayEnum::Stripe => $app->make(StripePaymentGateway::class),
                PaymentGatewayEnum::PayPal => $app->make(PayPalPaymentGateway::class),
                PaymentGatewayEnum::Zip, null => throw new RuntimeException("Unrecognized PAYMENT_GATEWAY value \"{$configured}\" — expected one of: stripe, paypal."),
            };
        });

        $this->app->singleton(PaymentGatewayResolver::class);

        // Same lazy-singleton posture as `PaymentGateway` above — never
        // constructed unless something actually sends an SMS notification,
        // so an empty MESSAGEMEDIA_API_KEY/SECRET in an environment that
        // never hits that path (or a test binding a fake `SmsProvider`
        // instead) never has to care. See
        // `App\Services\Notifications\MessageMediaClient`'s docblock —
        // MessageMedia sandbox access is unconfirmed on the business side
        // as of this phase.
        $this->app->singleton(SmsProvider::class, fn (): SmsProvider => new MessageMediaClient);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->validatePaymentGatewaySelection();
        $this->configureDefaults();
        $this->configureRateLimiting();
        $this->configureEventListeners();
        $this->configureObservers();
        $this->configureNotificationChannels();
        $this->configureDevCommands();
    }

    /**
     * `config('services.payment_gateway')` must be a recognized, built
     * gateway (`stripe`|`paypal`) — validated eagerly here, at boot, rather
     * than lazily inside the `PaymentGateway::class` singleton factory
     * (below), so a bad `PAYMENT_GATEWAY` value fails loud on every request
     * even if nothing in that particular request path ever resolves the
     * interface. Deliberately doesn't construct either concrete gateway
     * itself — just validates the string — so this stays compatible with
     * "both gateways stay lazy singletons" below. See
     * docs/architecture/03-integrations.md's PayPal section, point 1.
     */
    protected function validatePaymentGatewaySelection(): void
    {
        $configured = (string) config('services.payment_gateway');

        if (! in_array($configured, [PaymentGatewayEnum::Stripe->value, PaymentGatewayEnum::PayPal->value], true)) {
            throw new RuntimeException("Unrecognized PAYMENT_GATEWAY value \"{$configured}\" — expected one of: stripe, paypal.");
        }
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    /**
     * Named rate limiters for the customer OTP request/verify family (§6 of
     * docs/architecture/08-customer-auth-otp.md). One pair per purpose so a
     * flow's usage can't exhaust another flow's budget on the same email —
     * the password-login lockout family (§7) is deliberately separate; see
     * App\Services\Auth\PasswordLoginThrottleService.
     */
    protected function configureRateLimiting(): void
    {
        // Public enquiry form (contact/quote/fleet/notify-me): unauthenticated
        // and mail-triggering, so throttled per IP — 5/minute burst, 20/hour.
        RateLimiter::for('enquiries', fn (Request $request) => [
            Limit::perMinute(5)->by("enquiries-minute:{$request->ip()}"),
            Limit::perHour(20)->by("enquiries-hour:{$request->ip()}"),
        ]);

        // Newsletter sign-up: same posture as enquiries.
        RateLimiter::for('newsletter', fn (Request $request) => [
            Limit::perMinute(5)->by("newsletter-minute:{$request->ip()}"),
            Limit::perHour(20)->by("newsletter-hour:{$request->ip()}"),
        ]);

        // Read-only lookups that cost real work (pricing ladders, slot
        // availability, suburb typeahead): generous but bounded per IP.
        RateLimiter::for('public-lookups', fn (Request $request) => Limit::perMinute(120)->by("lookups:{$request->ip()}"));

        // Slot holds and order creation (the public write path of checkout).
        RateLimiter::for('checkout-writes', fn (Request $request) => Limit::perMinute(20)->by("checkout-writes:{$request->ip()}"));

        foreach (['registration', 'login', 'password_reset'] as $purpose) {
            RateLimiter::for("otp-request-{$purpose}", fn (Request $request) => $this->otpRequestLimits($request, $purpose));
            RateLimiter::for("otp-verify-{$purpose}", fn (Request $request) => $this->otpVerifyLimits($request, $purpose));
        }
    }

    /**
     * @return array<int, Limit>
     */
    protected function otpRequestLimits(Request $request, string $purpose): array
    {
        $email = $this->normalizedEmailFromRequest($request);

        return [
            // 60s resend cooldown, per email per purpose.
            Limit::perMinute(1)->by("otp-resend:{$purpose}:{$email}"),
            // 5/hour + 10/day request cap, per email per purpose.
            Limit::perHour(5)->by("otp-request-hour:{$purpose}:{$email}"),
            Limit::perDay(10)->by("otp-request-day:{$purpose}:{$email}"),
            // 20/hour per IP, across all purposes (same key regardless of
            // which purpose's limiter fires, so it aggregates naturally).
            Limit::perHour(20)->by("otp-request-ip:{$request->ip()}"),
        ];
    }

    /**
     * @return array<int, Limit>
     */
    protected function otpVerifyLimits(Request $request, string $purpose): array
    {
        $email = $this->normalizedEmailFromRequest($request);

        return [
            // 10/hour per email+IP, separate from the per-challenge attempts cap.
            Limit::perHour(10)->by("otp-verify:{$purpose}:{$email}:{$request->ip()}"),
        ];
    }

    protected function normalizedEmailFromRequest(Request $request): string
    {
        return Str::lower(trim((string) $request->input('email', '')));
    }

    /**
     * Every listener gets an explicit `Event::listen()` call here — this app
     * has no `App\Providers\EventServiceProvider`, and `bootstrap/app.php`
     * itself never calls `withEvents()`.
     *
     * CORRECTION (Phase 7, confirmed by reading framework source): despite
     * the above, `app/Listeners` IS zero-config auto-discovered anyway —
     * `Illuminate\Foundation\Application::configure()` calls `->withEvents()`
     * unconditionally, before `bootstrap/app.php`'s own chain ever runs. Any
     * public `handle*`/`__invoke` method on a class under `app/Listeners`
     * gets auto-registered for whatever event type(s) it's hinted for, IN
     * ADDITION to any explicit `Event::listen()` call below for that same
     * class — a real double-registration, not a hypothetical one (see
     * `App\Listeners\LogNotificationDelivery`'s docblock for the full
     * writeup and the bug it caused). Every listener registered below that
     * lives under `app/Listeners` MUST implement
     * `Illuminate\Contracts\Events\ShouldBeDiscovered` returning `false` to
     * opt out of that auto-discovery, or it will fire twice per dispatch.
     */
    protected function configureEventListeners(): void
    {
        // Standard Fortify/Laravel wiring for `Features::emailVerification()`
        // — fires only if something dispatches `Registered` in the future
        // (staff are currently provisioned directly via
        // Admin\UserController@store, which does not dispatch it).
        Event::listen(Registered::class, SendEmailVerificationNotification::class);

        // Staff-invite email-verification proof-of-ownership — see
        // App\Listeners\MarkStaffEmailAsVerifiedOnPasswordReset.
        Event::listen(PasswordReset::class, MarkStaffEmailAsVerifiedOnPasswordReset::class);

        // Phase 7 notification-delivery audit trail — see
        // App\Listeners\LogNotificationDelivery's docblock for why exactly
        // one of these two fires per channel per notification send, and why
        // this is the ONLY place `NotificationLog` rows get written.
        Event::listen(NotificationSent::class, LogNotificationDelivery::class);
        Event::listen(NotificationFailed::class, LogNotificationDelivery::class);
    }

    /**
     * `Promotion`/`PriceRule` audit-log wiring (Phase 5, §9 requirement) —
     * observers rather than explicit per-controller `AuditLog::create()`
     * calls, see {@see PromotionObserver}'s docblock for why.
     *
     * Phase 6 adds `ContentPageObserver`/`FaqObserver` (audit-log writing,
     * same pattern as `PromotionObserver`/`PriceRuleObserver` — their admin
     * CRUD controllers are super-admin-agent's to build in a follow-on
     * round, not backend-agent's this phase) and `FrontendRevalidationObserver`
     * for every model implementing `App\Contracts\RevalidatesFrontend` — one
     * generic observer registered per implementor. `ContentPage`/`Faq`/
     * `Promotion` each end up with two independent observers (audit-log +
     * revalidation); Eloquent dispatches every registered observer for the
     * same event without conflict.
     */
    protected function configureObservers(): void
    {
        Promotion::observe(PromotionObserver::class);
        PriceRule::observe(PriceRuleObserver::class);
        ContentPage::observe(ContentPageObserver::class);
        Faq::observe(FaqObserver::class);

        ContentPage::observe(FrontendRevalidationObserver::class);
        Faq::observe(FrontendRevalidationObserver::class);
        Brand::observe(FrontendRevalidationObserver::class);
        TyreModel::observe(FrontendRevalidationObserver::class);
        TyreVariant::observe(FrontendRevalidationObserver::class);
        Promotion::observe(FrontendRevalidationObserver::class);

        // Phase 7 booking-lifecycle notifications — see
        // App\Observers\BookingNotificationObserver's docblock.
        Booking::observe(BookingNotificationObserver::class);
    }

    /**
     * Register the `'sms'` notification channel under that driver name —
     * `Illuminate\Notifications\ChannelManager` (a `Manager` subclass) has
     * no built-in `createSmsDriver()`, so every `via()` returning the
     * literal string `'sms'` in this app (see `App\Notifications\*`) needs
     * this `Notification::extend()` call to resolve — Laravel's own
     * documented mechanism for a channel name that isn't a framework
     * built-in.
     */
    protected function configureNotificationChannels(): void
    {
        Notification::extend('sms', fn (Application $app): SmsChannel => $app->make(SmsChannel::class));
    }

    /**
     * Excludes `php artisan dev`'s bundled native `queue:listen` process
     * when (and only when) the Compose `queue-worker` container is already
     * running as its own consumer of the same queue (database by default) — see
     * {@see DevQueueGuard}'s docblock for the full incident/reasoning this
     * follows up on and why this is conditional, not unconditional.
     * `DevCommands::except('queue')` is Laravel's own documented mechanism
     * for opting a specific bundled `dev` process out ('queue' is the name
     * `Illuminate\Foundation\DevCommands::registerDefaults()` registers its
     * `queue:listen` entry under).
     */
    protected function configureDevCommands(): void
    {
        if (DevQueueGuard::shouldExcludeNativeQueueListener($this->app->runningInConsole(), $_SERVER['argv'] ?? [])) {
            DevCommands::except('queue');
        }
    }
}
