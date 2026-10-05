<?php

namespace App\Observers;

use App\Models\AuditLog;
use App\Models\Faq;
use Illuminate\Support\Facades\Auth;

/**
 * Writes an {@see AuditLog} row on every {@see Faq} create/update/delete —
 * same observer-based approach as {@see PromotionObserver}/
 * {@see ContentPageObserver} (see `PromotionObserver`'s docblock for why an
 * observer rather than an explicit controller-side call). Registered in
 * `AppServiceProvider::boot()`, alongside (not instead of)
 * `FrontendRevalidationObserver`.
 *
 * **Do not also add an explicit `AuditLog::create()` call for `Faq`
 * mutations anywhere else** — that would double-log.
 */
class FaqObserver
{
    public function created(Faq $faq): void
    {
        $this->log($faq, 'faqs.created', before: null, after: $faq->getAttributes());
    }

    public function updated(Faq $faq): void
    {
        $changes = $faq->getChanges();

        if ($changes === []) {
            return;
        }

        $before = array_intersect_key($faq->getOriginal(), $changes);

        $this->log($faq, 'faqs.updated', $before, $changes);
    }

    public function deleted(Faq $faq): void
    {
        $this->log($faq, 'faqs.deleted', before: $faq->getAttributes(), after: null);
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function log(Faq $faq, string $action, ?array $before, ?array $after): void
    {
        AuditLog::create([
            'auditable_type' => Faq::class,
            'auditable_id' => $faq->id,
            'action' => $action,
            'actor_id' => Auth::id(),
            'before' => $before,
            'after' => $after,
        ]);
    }
}
