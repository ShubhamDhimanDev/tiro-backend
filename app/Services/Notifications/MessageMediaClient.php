<?php

namespace App\Services\Notifications;

use App\Contracts\Notifications\SmsProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Direct HTTP wrapper around MessageMedia's documented Messages REST API
 * (`POST {base_url}/messages`) — no first-party or usable community Laravel
 * package exists for this (confirmed), so this codes against the plain
 * documented contract via the `Http` facade rather than a vendor SDK. See
 * config('services.messagemedia.*') for the credential/env-var mapping.
 *
 * Auth: HTTP Basic, `api_key` as the username and `api_secret` as the
 * password — MessageMedia's own "Basic Auth" scheme (the alternative HMAC
 * scheme it also documents is not implemented here).
 *
 * Twilio is explicitly NOT being built this phase — no failover state
 * machine, no second provider. {@see SmsProvider}
 * is what keeps a future swap cheap; this class deliberately does not build
 * beyond it (no retry/backoff of its own — that's
 * `App\Notifications\Channels\SmsChannel`/the notification queue's job, same
 * "the HTTP wrapper does the one call, the queue owns retries" split as
 * `App\Jobs\NotifyFrontendRevalidation` vs. its own webhook target).
 *
 * MessageMedia sandbox/developer-account access is unconfirmed on the
 * business side (same category as Zip's BNPL merchant approval, Phase 4) —
 * this does not block building/testing this class: every test exercises it
 * entirely via `Http::fake()`, never a real call.
 */
final class MessageMediaClient implements SmsProvider
{
    public function send(string $to, string $body): SmsSendResult
    {
        $baseUrl = rtrim((string) config('services.messagemedia.base_url'), '/');

        $response = Http::withBasicAuth(
            (string) config('services.messagemedia.api_key'),
            (string) config('services.messagemedia.api_secret'),
        )
            // A POST carrying HTTP Basic Auth credentials must never
            // silently follow a redirect (Guzzle's default) — see security
            // review, Phase 7 item 3.
            ->withoutRedirecting()
            ->timeout(10)
            ->post("{$baseUrl}/messages", [
                'messages' => [
                    [
                        'content' => $body,
                        'destination_number' => $to,
                    ],
                ],
            ]);

        if (! $response->successful()) {
            // Deliberately NOT the raw response body (see security review,
            // Phase 7 item 1): MessageMedia's own request shape echoes
            // submitted `content`/`destination_number` back inside a
            // `messages` array on a per-message validation error, so
            // logging the body wholesale risks writing actual SMS text
            // into a plain log file — the exact "metadata only, never
            // content" intent `NotificationLog` is built around, which
            // should hold project-wide, not just for that one table. Only a
            // narrow, known-safe top-level `code`/`message` pair (if
            // present as plain strings) is captured, never anything nested.
            $code = $response->json('code');
            $message = $response->json('message');

            Log::warning('MessageMedia SMS send failed.', [
                'status' => $response->status(),
                'error_code' => is_string($code) ? $code : null,
                'error_message' => is_string($message) ? $message : null,
            ]);

            return new SmsSendResult(
                success: false,
                error: "MessageMedia returned status {$response->status()}.",
            );
        }

        $messageId = $response->json('messages.0.message_id');

        return new SmsSendResult(
            success: true,
            providerMessageId: is_string($messageId) ? $messageId : null,
        );
    }
}
