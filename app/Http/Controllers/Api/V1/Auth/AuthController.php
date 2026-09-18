<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Exceptions\Auth\InvalidOtpException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Auth\LoginCustomerRequest;
use App\Http\Requests\Api\Auth\RegisterCustomerRequest;
use App\Http\Requests\Api\Auth\RequestOtpRequest;
use App\Http\Requests\Api\Auth\RequestPasswordResetRequest;
use App\Http\Requests\Api\Auth\VerifyOtpRequest;
use App\Http\Requests\Api\Auth\VerifyPasswordResetRequest;
use App\Http\Requests\Api\Auth\VerifyRegistrationRequest;
use App\Http\Resources\AuthSessionResource;
use App\Models\Customer;
use App\Models\EmailOtpChallenge;
use App\Services\Auth\EmailOtpService;
use App\Services\Auth\PasswordLoginThrottleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Customer registration/login/OTP/password-reset API surface — see
 * docs/architecture/08-customer-auth-otp.md for the full contract this
 * implements. Response shapes/status codes follow §12 exactly.
 */
class AuthController extends Controller
{
    /**
     * Sliding-session recommendation from §9 — fixed 90-day expiry set at
     * issuance. The "sliding" extend-on-activity renewal §9 also describes
     * is NOT implemented here (flagged as a known gap; §9 itself notes the
     * whole 90-day figure is a recommendation pending product sign-off, not
     * a settled spec).
     */
    private const SESSION_TTL_DAYS = 90;

    /**
     * Precomputed bcrypt hash of a fixed, random dummy value — checked via
     * `Hash::check()` when no customer row exists for the submitted email,
     * so `login()` always pays the same hashing cost whether or not the
     * account exists. Fixed and hardcoded rather than generated per-request
     * (regenerating would itself add variable cost and defeats the point).
     * Value has no meaning beyond "a bcrypt hash nothing will ever match" —
     * see security review, Phase 0 auth, item 4.
     */
    private const DUMMY_PASSWORD_HASH = '$2y$12$XR0.pZSZcdmMoTA5PE7RaODlb0dzgay6oBCzG4EPBxR0gq6vSUTD2';

    public function __construct(
        private readonly EmailOtpService $otp,
        private readonly PasswordLoginThrottleService $loginThrottle,
    ) {}

    public function register(RegisterCustomerRequest $request): JsonResponse
    {
        $email = $request->validated('email');

        $customer = Customer::query()->where('email', $email)->first();

        if ($customer !== null && $customer->email_verified_at !== null) {
            throw ValidationException::withMessages([
                'email' => [__('An account already exists for this email — log in instead.')],
            ]);
        }

        $customer ??= Customer::create([
            'name' => $request->validated('name') ?: $this->defaultNameFromEmail($email),
            'email' => $email,
        ]);

        $this->otp->issue(
            email: $email,
            purpose: 'registration',
            request: $request,
            pendingPasswordHash: Hash::make($request->validated('password')),
        );

        return response()->json([
            'message' => __('We have emailed you a verification code to finish creating your account.'),
        ]);
    }

    public function verifyRegistration(VerifyRegistrationRequest $request): JsonResponse
    {
        $challenge = $this->verifyChallengeOr422(
            $request->validated('email'),
            $request->validated('code'),
            'registration',
        );

        $customer = Customer::query()->where('email', $request->validated('email'))->firstOrFail();

        $customer->forceFill([
            'password' => $challenge->pending_password_hash,
            'email_verified_at' => now(),
        ])->save();

        $this->otp->consume($challenge);

        return $this->issueSessionResponse($customer);
    }

    public function login(LoginCustomerRequest $request): JsonResponse
    {
        $email = $request->validated('email');
        $ip = (string) $request->ip();

        $this->loginThrottle->ensureNotLocked($email, $ip);

        $customer = Customer::query()->where('email', $email)->first();

        // Always run Hash::check — against the real hash if one exists, or
        // a fixed dummy hash otherwise — so a nonexistent/unset-password
        // account fails in the same amount of time as a wrong password on a
        // real one (timing side-channel fix, see security review item 4).
        $customerPassword = $customer?->password;
        $storedHash = $customerPassword ?? self::DUMMY_PASSWORD_HASH;

        $passwordMatches = Hash::check($request->validated('password'), $storedHash);

        $valid = $customer !== null
            && $customer->email_verified_at !== null
            && $customer->password !== null
            && $passwordMatches;

        if (! $valid) {
            $this->loginThrottle->recordFailure($email, $ip);

            return response()->json([
                'message' => __('These credentials do not match our records.'),
            ], 401);
        }

        $this->loginThrottle->recordSuccess($email);

        return $this->issueSessionResponse($customer);
    }

    public function requestOtp(RequestOtpRequest $request): JsonResponse
    {
        $this->otp->issue(
            email: $request->validated('email'),
            purpose: 'login',
            request: $request,
        );

        return response()->json([
            'message' => __('If that email is registered, we have sent a login code.'),
        ]);
    }

    public function verifyOtp(VerifyOtpRequest $request): JsonResponse
    {
        $challenge = $this->verifyChallengeOr422(
            $request->validated('email'),
            $request->validated('code'),
            'login',
        );

        $customer = Customer::query()->where('email', $request->validated('email'))->first();

        if ($customer === null || $customer->email_verified_at === null) {
            $this->otp->consume($challenge);

            return response()->json([
                'message' => __('No account found for this email — register to continue.'),
            ], 404);
        }

        $this->otp->consume($challenge);

        return $this->issueSessionResponse($customer);
    }

    public function requestPasswordReset(RequestPasswordResetRequest $request): JsonResponse
    {
        // Always create the challenge row and queue the mail dispatch, same
        // as requestOtp() — whether the email actually belongs to an
        // activated Customer is decided inside OtpCodeMail::shouldDeliver(),
        // at send time in the queue worker, not here. Deciding it here and
        // skipping the DB write + queue push for nonexistent accounts made
        // response time a distinguishable signal (timing side-channel fix,
        // see security review item 3).
        $this->otp->issue(email: $request->validated('email'), purpose: 'password_reset', request: $request);

        return response()->json([
            'message' => __('If that email has an account, we have sent a password reset code.'),
        ]);
    }

    public function verifyPasswordReset(VerifyPasswordResetRequest $request): JsonResponse
    {
        $challenge = $this->verifyChallengeOr422(
            $request->validated('email'),
            $request->validated('code'),
            'password_reset',
        );

        $passwordValidator = Validator::make(
            ['password' => $request->validated('new_password')],
            ['password' => ['required', 'string', Password::min(8)->uncompromised()]],
        );

        if ($passwordValidator->fails()) {
            throw ValidationException::withMessages([
                'password' => $passwordValidator->errors()->get('password'),
            ]);
        }

        $customer = Customer::query()->where('email', $request->validated('email'))->firstOrFail();

        $customer->forceFill(['password' => $request->validated('new_password')])->save();

        $this->otp->consume($challenge);

        $customer->tokens()->delete();

        return $this->issueSessionResponse($customer);
    }

    public function destroySession(Request $request): Response
    {
        /** @var Customer|null $customer */
        $customer = $request->user('customer');

        $customer?->currentAccessToken()?->delete();

        return response()->noContent();
    }

    public function destroyAllSessions(Request $request): Response
    {
        /** @var Customer|null $customer */
        $customer = $request->user('customer');

        $customer?->tokens()->delete();

        return response()->noContent();
    }

    /**
     * @throws ValidationException 422 {message, errors: {code: [...]}}
     */
    private function verifyChallengeOr422(string $email, string $code, string $purpose): EmailOtpChallenge
    {
        try {
            return $this->otp->verify($email, $code, $purpose);
        } catch (InvalidOtpException) {
            throw ValidationException::withMessages([
                'code' => [__('This code is invalid or has expired.')],
            ]);
        }
    }

    private function issueSessionResponse(Customer $customer): JsonResponse
    {
        $expiresAt = now()->addDays(self::SESSION_TTL_DAYS);

        $token = $customer->createToken('storefront', ['*'], $expiresAt);

        return (new AuthSessionResource([
            'token' => $token->plainTextToken,
            'expires_at' => $expiresAt,
            'customer' => $customer,
        ]))->response();
    }

    /**
     * `customers.name` is NOT NULL, but the documented register request only
     * collects {email, password} — derive a placeholder from the email's
     * local part rather than requiring a field the frontend contract never
     * specified. Editable later via an account-settings endpoint (out of
     * scope here).
     */
    private function defaultNameFromEmail(string $email): string
    {
        $localPart = Str::before($email, '@');

        return Str::title(str_replace(['.', '_', '+', '-'], ' ', $localPart));
    }
}
