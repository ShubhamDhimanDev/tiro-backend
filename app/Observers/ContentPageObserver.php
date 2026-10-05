<?php

namespace App\Observers;

use App\Models\AuditLog;
use App\Models\ContentPage;
use Illuminate\Support\Facades\Auth;

/**
 * Writes an {@see AuditLog} row on every {@see ContentPage} create/update/
 * delete — same observer-based approach as {@see PromotionObserver} (see
 * that class's docblock for why an observer rather than an explicit
 * controller-side call: the admin CMS CRUD screen/controller for this
 * entity is super-admin-agent's to build in a follow-on round, not
 * backend-agent's this phase — an observer guarantees audit coverage
 * regardless of which controller ends up writing to this model).
 * Registered in `AppServiceProvider::boot()`, alongside (not instead of)
 * `FrontendRevalidationObserver` — the two are independent concerns on the
 * same model.
 *
 * **Do not also add an explicit `AuditLog::create()` call for `ContentPage`
 * mutations anywhere else** — that would double-log.
 */
class ContentPageObserver
{
    public function created(ContentPage $contentPage): void
    {
        $this->log($contentPage, 'content_pages.created', before: null, after: $contentPage->getAttributes());
    }

    public function updated(ContentPage $contentPage): void
    {
        $changes = $contentPage->getChanges();

        if ($changes === []) {
            return;
        }

        $before = array_intersect_key($contentPage->getOriginal(), $changes);

        $this->log($contentPage, 'content_pages.updated', $before, $changes);
    }

    public function deleted(ContentPage $contentPage): void
    {
        $this->log($contentPage, 'content_pages.deleted', before: $contentPage->getAttributes(), after: null);
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function log(ContentPage $contentPage, string $action, ?array $before, ?array $after): void
    {
        AuditLog::create([
            'auditable_type' => ContentPage::class,
            'auditable_id' => $contentPage->id,
            'action' => $action,
            'actor_id' => Auth::id(),
            'before' => $before,
            'after' => $after,
        ]);
    }
}
