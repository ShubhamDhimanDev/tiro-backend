<?php

use App\Jobs\NotifyFrontendRevalidation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * `App\Jobs\NotifyFrontendRevalidation` — the queued webhook POST behind
 * `App\Observers\FrontendRevalidationObserver`. See the Phase 6 task
 * brief's "ISR on-demand revalidation" section for the payload/auth
 * contract.
 */
beforeEach(function () {
    config([
        'services.frontend.revalidate_url' => 'https://frontend.test/api/revalidate',
        'services.frontend.revalidate_secret' => 'test-shared-secret',
    ]);
});

it('POSTs the tags with the shared-secret header to the configured URL', function () {
    Http::fake(['frontend.test/*' => Http::response(['revalidated' => true, 'tags' => ['content:blog_post']], 200)]);

    (new NotifyFrontendRevalidation(['content:blog_post', 'content:blog_post:my-post']))->handle();

    Http::assertSent(function ($request) {
        return $request->url() === 'https://frontend.test/api/revalidate'
            && $request->header('X-Revalidate-Secret') === ['test-shared-secret']
            && $request['tags'] === ['content:blog_post', 'content:blog_post:my-post'];
    });
});

it('throws on a non-200 response so the queue retries it', function () {
    Http::fake(['frontend.test/*' => Http::response(['error' => 'nope'], 500)]);

    (new NotifyFrontendRevalidation(['content:blog_post']))->handle();
})->throws(RuntimeException::class);

it('skips the HTTP call and logs a warning when the URL/secret are not configured', function () {
    config(['services.frontend.revalidate_url' => null, 'services.frontend.revalidate_secret' => null]);
    Http::fake();
    Log::spy();

    (new NotifyFrontendRevalidation(['content:blog_post']))->handle();

    Http::assertNothingSent();
    Log::shouldHaveReceived('warning')->once();
});

it('logs a warning on final failure instead of throwing', function () {
    Log::spy();

    (new NotifyFrontendRevalidation(['content:blog_post']))->failed(new RuntimeException('boom'));

    Log::shouldHaveReceived('warning')->once();
});

it('is a no-op for an empty tag list', function () {
    Http::fake();

    (new NotifyFrontendRevalidation([]))->handle();

    Http::assertNothingSent();
});
