<?php

use App\Models\ContentPage;
use App\Models\Faq;
use App\Rules\NoPlaceholderTokens;
use App\Support\ContentPlaceholderResolver;
use App\Support\ContentTokens;
use Database\Seeders\LaunchContentSeeder;

it('detects unresolved upper-case tokens but not normal brackets', function (): void {
    expect(ContentTokens::find('Up to [MAX_BOOKING_WINDOW_DAYS] days, ABN [ABN PLACEHOLDER], [STATE/TERRITORY]'))
        ->toBe(['[MAX_BOOKING_WINDOW_DAYS]', '[ABN PLACEHOLDER]', '[STATE/TERRITORY]'])
        ->and(ContentTokens::find('See [Read more](/x) and [1] and [a]'))->toBe([]);
});

it('resolves tokens with config values and falls back to natural wording', function (): void {
    config(['business.privacy_email' => null, 'business.support_email' => null, 'business.abn' => null]);

    $html = ContentPlaceholderResolver::resolve('<p>Email us at [PRIVACY_EMAIL] today.</p><p><em>Tiro Mobile Tyres — ABN [ABN PLACEHOLDER]. This document is a draft.</em></p>');

    expect(ContentTokens::find($html))->toBe([])
        ->and($html)->toContain('through our Contact page')->not->toContain('ABN');

    config(['business.privacy_email' => 'privacy@tiro.test', 'business.abn' => '12 345 678 901']);

    $html = ContentPlaceholderResolver::resolve('<p>Email us at [PRIVACY_EMAIL].</p><p><em>Tiro Mobile Tyres — ABN [ABN PLACEHOLDER]. draft</em></p>');

    expect($html)->toContain('at privacy@tiro.test')->toContain('ABN 12 345 678 901');
});

it('seeds launch content with no placeholder tokens and serves the terms page at /pages/terms', function (): void {
    $this->seed(LaunchContentSeeder::class);

    ContentPage::query()->get()->each(fn (ContentPage $page) => expect(ContentTokens::find($page->body))->toBe([]));
    Faq::query()->get()->each(fn (Faq $faq) => expect(ContentTokens::find($faq->answer))->toBe([]));

    $this->getJson('/api/v1/content/pages/page/terms')->assertOk()->assertJsonPath('data.slug', 'terms-conditions');
    $this->getJson('/api/v1/content/pages/page/privacy')->assertOk()->assertJsonPath('data.slug', 'privacy-policy');
});

it('rejects placeholder tokens when saving content through the admin validator', function (): void {
    $validator = validator(
        ['answer' => 'Book up to [MAX_BOOKING_WINDOW_DAYS] days ahead'],
        ['answer' => [new NoPlaceholderTokens]],
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('answer'))->toContain('[MAX_BOOKING_WINDOW_DAYS]');
});
