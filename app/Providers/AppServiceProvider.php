<?php

namespace App\Providers;

use App\Listeners\MarkStaffEmailAsVerifiedOnPasswordReset;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimiting();
        $this->configureEventListeners();
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
     * This app has no `App\Providers\EventServiceProvider` (and never calls
     * `withEvents()` in bootstrap/app.php), so `app/Listeners` is NOT
     * auto-discovered — every listener needs an explicit `Event::listen()`
     * call here.
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
    }
}
