<?php

use App\Models\NewsletterSubscriber;
use Illuminate\Support\Facades\RateLimiter;

/**
 * `POST /api/v1/newsletter-subscriptions` (see
 * docs/redesign/api-contract-phase7.md section 6).
 */
beforeEach(function () {
    RateLimiter::clear('newsletter-minute:127.0.0.1');
    RateLimiter::clear('newsletter-hour:127.0.0.1');
});

it('subscribes an address and normalises the email', function () {
    $this->postJson('/api/v1/newsletter-subscriptions', ['email' => '  Jane@Example.COM ', 'first_name' => 'Jane', 'source' => 'home'])
        ->assertCreated()
        ->assertExactJson(['data' => ['message' => "Thanks, you're on the list."]]);

    $subscriber = NewsletterSubscriber::query()->sole();
    expect($subscriber->email)->toBe('jane@example.com')
        ->and($subscriber->first_name)->toBe('Jane')
        ->and($subscriber->source)->toBe('home')
        ->and($subscriber->unsubscribed_at)->toBeNull();
});

it('answers identically for repeats and reactivates unsubscribed addresses', function () {
    $existing = NewsletterSubscriber::factory()->create(['email' => 'back@example.com', 'unsubscribed_at' => now()->subDay()]);

    $this->postJson('/api/v1/newsletter-subscriptions', ['email' => 'back@example.com'])->assertCreated();
    $this->postJson('/api/v1/newsletter-subscriptions', ['email' => 'back@example.com'])->assertCreated();

    expect(NewsletterSubscriber::query()->count())->toBe(1)
        ->and($existing->fresh()->unsubscribed_at)->toBeNull();
});

it('stores nothing when the honeypot is filled but still answers 201', function () {
    $this->postJson('/api/v1/newsletter-subscriptions', ['email' => 'bot@example.com', 'website' => 'http://spam.example'])
        ->assertCreated();

    expect(NewsletterSubscriber::query()->count())->toBe(0);
});

it('validates the email and source', function () {
    $this->postJson('/api/v1/newsletter-subscriptions', ['email' => 'nope'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.email.0', 'That email address does not look right. Check it and try again.');
    $this->postJson('/api/v1/newsletter-subscriptions', [])->assertUnprocessable()->assertJsonValidationErrors('email');
    $this->postJson('/api/v1/newsletter-subscriptions', ['email' => 'a@example.com', 'source' => 'billboard'])->assertUnprocessable()->assertJsonValidationErrors('source');
});

it('throttles to five requests per minute per IP', function () {
    foreach (range(1, 5) as $i) {
        $this->postJson('/api/v1/newsletter-subscriptions', ['email' => "u{$i}@example.com"])->assertCreated();
    }

    $this->postJson('/api/v1/newsletter-subscriptions', ['email' => 'u6@example.com'])->assertTooManyRequests();
});
