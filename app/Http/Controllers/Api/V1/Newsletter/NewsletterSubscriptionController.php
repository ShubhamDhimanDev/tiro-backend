<?php

namespace App\Http\Controllers\Api\V1\Newsletter;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Newsletter\StoreNewsletterSubscriptionRequest;
use App\Models\NewsletterSubscriber;
use Illuminate\Http\JsonResponse;

/**
 * `POST /api/v1/newsletter-subscriptions` — public, unauthenticated,
 * throttled per IP (`throttle:newsletter`). Always answers the same 201 so a
 * caller cannot learn whether an address was already subscribed. A filled
 * `website` honeypot is answered identically but stores nothing.
 */
class NewsletterSubscriptionController extends Controller
{
    public function store(StoreNewsletterSubscriptionRequest $request): JsonResponse
    {
        if (blank($request->input('website'))) {
            NewsletterSubscriber::subscribe(
                (string) $request->validated('email'),
                $request->validated('first_name'),
                (string) ($request->validated('source') ?? 'footer'),
                $request->ip(),
            );
        }

        return response()->json(['data' => ['message' => "Thanks, you're on the list."]], 201);
    }
}
