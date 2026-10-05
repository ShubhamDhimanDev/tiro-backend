<?php

use App\Models\AuditLog;
use App\Models\Faq;

/**
 * `App\Observers\FaqObserver` — see `ContentPageAuditLogObserverTest`'s
 * docblock for why this exercises the model directly.
 */
it('writes a faqs.created row with no before and the full after snapshot', function () {
    $faq = Faq::factory()->create(['question' => 'Do you fit run-flat tyres?']);

    $log = AuditLog::query()->where('auditable_type', Faq::class)->where('auditable_id', $faq->id)->sole();

    expect($log->action)->toBe('faqs.created');
    expect($log->before)->toBeNull();
    expect($log->after['question'])->toBe('Do you fit run-flat tyres?');
});

it('writes a faqs.updated row with only the changed fields', function () {
    $faq = Faq::factory()->create(['answer' => 'Original answer']);

    $faq->update(['answer' => 'Updated answer']);

    $log = AuditLog::query()
        ->where('auditable_type', Faq::class)
        ->where('auditable_id', $faq->id)
        ->where('action', 'faqs.updated')
        ->sole();

    expect($log->before)->toBe(['answer' => 'Original answer']);
    expect($log->after)->toBe(['answer' => 'Updated answer']);
});

it('writes a faqs.deleted row with the full before snapshot and no after', function () {
    $faq = Faq::factory()->create();
    $faqId = $faq->id;

    $faq->delete();

    $log = AuditLog::query()->where('auditable_type', Faq::class)->where('auditable_id', $faqId)->where('action', 'faqs.deleted')->sole();

    expect($log->after)->toBeNull();
    expect($log->before)->not->toBeNull();
});
