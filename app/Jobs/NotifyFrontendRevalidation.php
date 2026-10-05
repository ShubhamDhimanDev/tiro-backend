<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * POSTs `{ "tags": [...] }` to `FRONTEND_REVALIDATE_URL`
 * (`config('services.frontend.revalidate_url')`) so Next.js can call
 * `revalidateTag()` per tag — see `App\Contracts\RevalidatesFrontend` and
 * `App\Observers\FrontendRevalidationObserver` for the dispatch side.
 *
 * Always queued (on the default queue connection — `database` out of the box,
 * Redis only if opted in via `QUEUE_CONNECTION=redis`), never a
 * synchronous HTTP call inside the admin request/response cycle — an
 * admin's save shouldn't wait on Next.js being reachable.
 *
 * Reliability posture: best-effort, not the system of record for
 * freshness — same posture already applied to the OTP mail queue vs.
 * general mail in Phase 0. A handful of retries with backoff, then a
 * `Log::warning` on final failure and no further escalation; the existing
 * timed ISR revalidation window remains the fallback regardless of webhook
 * delivery success.
 */
class NotifyFrontendRevalidation implements ShouldQueue
{
    use Queueable;

    /**
     * A handful of attempts, not indefinite retry — this is a best-effort
     * cache-freshness nudge, not a durable delivery guarantee (see class
     * docblock).
     */
    public int $tries = 3;

    /**
     * @param  list<string>  $tags
     */
    public function __construct(public readonly array $tags) {}

    /**
     * Seconds to wait before each retry.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(): void
    {
        if ($this->tags === []) {
            return;
        }

        $url = config('services.frontend.revalidate_url');
        $secret = config('services.frontend.revalidate_secret');

        if (blank($url) || blank($secret)) {
            Log::warning('Skipping frontend ISR revalidation: FRONTEND_REVALIDATE_URL/FRONTEND_REVALIDATE_SECRET not configured.', [
                'tags' => $this->tags,
            ]);

            return;
        }

        $response = Http::withHeaders(['X-Revalidate-Secret' => $secret])
            ->timeout(5)
            ->post($url, ['tags' => $this->tags]);

        // Expected response is exactly `200 { "revalidated": true, "tags":
        // [...] }` — any non-200 is treated as a failure for retry
        // purposes, per the webhook contract.
        if (! $response->ok()) {
            throw new RuntimeException("Frontend ISR revalidation webhook returned status {$response->status()}.");
        }
    }

    /**
     * Final-failure logging only — no further escalation, see the class
     * docblock's reliability posture.
     */
    public function failed(?Throwable $exception): void
    {
        Log::warning('Frontend ISR revalidation failed after all retry attempts.', [
            'tags' => $this->tags,
            'error' => $exception?->getMessage(),
        ]);
    }
}
