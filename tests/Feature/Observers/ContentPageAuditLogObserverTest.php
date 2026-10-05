<?php

use App\Models\AuditLog;
use App\Models\ContentPage;

/**
 * `App\Observers\ContentPageObserver` — same observer-based audit-log
 * pattern as `PromotionObserver`/`PriceRuleObserver`, see that class's
 * docblock for why. No admin `ContentPage` controller exists yet
 * (super-admin-agent's follow-on round), so this exercises the model
 * mutation directly rather than through a controller, same posture as
 * `FrontendRevalidationObserverTest`.
 */
it('writes a content_pages.created row with no before and the full after snapshot', function () {
    $page = ContentPage::factory()->create(['title' => 'Winter tyre guide']);

    $log = AuditLog::query()->where('auditable_type', ContentPage::class)->where('auditable_id', $page->id)->sole();

    expect($log->action)->toBe('content_pages.created');
    expect($log->before)->toBeNull();
    expect($log->after['title'])->toBe('Winter tyre guide');
});

it('writes a content_pages.updated row with only the changed fields', function () {
    $page = ContentPage::factory()->create(['title' => 'Original title']);

    $page->update(['title' => 'Updated title']);

    $log = AuditLog::query()
        ->where('auditable_type', ContentPage::class)
        ->where('auditable_id', $page->id)
        ->where('action', 'content_pages.updated')
        ->sole();

    expect($log->before)->toBe(['title' => 'Original title']);
    expect($log->after)->toBe(['title' => 'Updated title']);
});

it('does not write an audit log row for a no-op save', function () {
    $page = ContentPage::factory()->create();

    $page->save();

    expect(AuditLog::query()->where('auditable_type', ContentPage::class)->where('action', 'content_pages.updated')->count())->toBe(0);
});

it('writes a content_pages.deleted row with the full before snapshot and no after', function () {
    $page = ContentPage::factory()->create();
    $pageId = $page->id;

    $page->delete();

    $log = AuditLog::query()->where('auditable_type', ContentPage::class)->where('auditable_id', $pageId)->where('action', 'content_pages.deleted')->sole();

    expect($log->after)->toBeNull();
    expect($log->before)->not->toBeNull();
});
