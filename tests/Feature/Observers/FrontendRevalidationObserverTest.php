<?php

use App\Enums\ContentPageType;
use App\Jobs\NotifyFrontendRevalidation;
use App\Models\Brand;
use App\Models\ContentPage;
use App\Models\Faq;
use App\Models\Promotion;
use App\Models\TyreModel;
use App\Models\TyreVariant;
use Illuminate\Support\Facades\Bus;

/**
 * `App\Observers\FrontendRevalidationObserver`, registered against every
 * `App\Contracts\RevalidatesFrontend` implementor in
 * `AppServiceProvider::configureObservers()` — see the Phase 6 task brief's
 * "ISR on-demand revalidation" section for the full tag table.
 */
it('dispatches content and listing tags on ContentPage create/update/delete', function () {
    Bus::fake();

    $page = ContentPage::factory()->ofType(ContentPageType::BlogPost)->create(['slug' => 'winter-tyre-guide']);

    Bus::assertDispatched(NotifyFrontendRevalidation::class, fn ($job) => $job->tags === [
        'content:blog_post', 'content:blog_post:winter-tyre-guide',
    ]);

    Bus::fake();
    $page->update(['title' => 'Updated title']);

    Bus::assertDispatched(NotifyFrontendRevalidation::class, fn ($job) => $job->tags === [
        'content:blog_post', 'content:blog_post:winter-tyre-guide',
    ]);

    Bus::fake();
    $page->delete();

    Bus::assertDispatched(NotifyFrontendRevalidation::class, fn ($job) => $job->tags === [
        'content:blog_post', 'content:blog_post:winter-tyre-guide',
    ]);
});

it('does not dispatch on a no-op ContentPage save', function () {
    $page = ContentPage::factory()->create();

    Bus::fake();
    $page->save();

    Bus::assertNotDispatched(NotifyFrontendRevalidation::class);
});

it('dispatches the global faq tag plus category/page tags when set', function () {
    Bus::fake();

    $page = ContentPage::factory()->ofType(ContentPageType::Guide)->create();
    Bus::fake(); // reset after the ContentPage's own create dispatch

    $faq = Faq::factory()->create(['category' => 'pdp', 'content_page_id' => $page->id]);

    Bus::assertDispatched(NotifyFrontendRevalidation::class, fn ($job) => $job->tags === [
        'content:faq', 'content:faq:pdp', "content:faq:page:{$page->id}",
    ]);
});

it('dispatches only the global faq tag when category and page are unset', function () {
    Bus::fake();

    $faq = Faq::factory()->create();

    Bus::assertDispatched(NotifyFrontendRevalidation::class, fn ($job) => $job->tags === ['content:faq']);
});

it('dispatches the brand list tag on create, and the list and page tags on update and delete', function () {
    Bus::fake();
    $brand = Brand::factory()->create(['slug' => 'bridgestone']);
    Bus::assertDispatched(NotifyFrontendRevalidation::class, fn ($job) => $job->tags === ['content:brand:list']);

    Bus::fake();
    $brand->update(['logo_path' => 'http://localhost/storage/media/bridgestone.webp']);
    Bus::assertDispatched(NotifyFrontendRevalidation::class, fn ($job) => $job->tags === ['content:brand:list', 'content:brand:bridgestone']);

    Bus::fake();
    $brand->delete();
    Bus::assertDispatched(NotifyFrontendRevalidation::class, fn ($job) => $job->tags === ['content:brand:list', 'content:brand:bridgestone']);
});

it('does not dispatch on TyreModel create or an ISR-irrelevant field update', function () {
    $brand = Brand::factory()->create(); // the brand's own create dispatch is not what's under test

    Bus::fake();
    $model = TyreModel::factory()->for($brand)->create(['slug' => 'turanza-t005']);
    Bus::assertNotDispatched(NotifyFrontendRevalidation::class);

    Bus::fake();
    $model->update(['released_at' => now()->subYear()]); // not in TyreModelDetailResource
    Bus::assertNotDispatched(NotifyFrontendRevalidation::class);
});

it('dispatches on a TyreModel ISR-relevant field update and on delete', function () {
    $model = TyreModel::factory()->create(['slug' => 'turanza-t005']);

    Bus::fake();
    $model->update(['name' => 'Turanza T005A']);
    Bus::assertDispatched(NotifyFrontendRevalidation::class, fn ($job) => $job->tags === ['content:tyre_model:turanza-t005']);

    Bus::fake();
    $model->delete();
    Bus::assertDispatched(NotifyFrontendRevalidation::class, fn ($job) => $job->tags === ['content:tyre_model:turanza-t005']);
});

it('does not dispatch on TyreVariant create or a price-only update', function () {
    $model = TyreModel::factory()->create(); // parent chain (incl. its brand) is not what's under test

    Bus::fake();
    $variant = TyreVariant::factory()->for($model)->create();
    Bus::assertNotDispatched(NotifyFrontendRevalidation::class);

    Bus::fake();
    $variant->update(['base_price' => $variant->base_price + 5000]);
    Bus::assertNotDispatched(NotifyFrontendRevalidation::class);
});

it('dispatches on a TyreVariant ISR-relevant field update and on delete', function () {
    $variant = TyreVariant::factory()->create();
    $slug = $variant->slug;
    // Derived from the factory's own current value (not a hardcoded literal) so the
    // update is always dirty, regardless of which value the factory happened to roll.
    $newLoadIndex = (string) ((int) $variant->load_index + 1);

    Bus::fake();
    $variant->update(['load_index' => $newLoadIndex]);
    Bus::assertDispatched(NotifyFrontendRevalidation::class, fn ($job) => $job->tags === ["content:tyre:{$slug}"]);

    Bus::fake();
    $variant->delete();
    Bus::assertDispatched(NotifyFrontendRevalidation::class, fn ($job) => $job->tags === ["content:tyre:{$slug}"]);
});

it('does not dispatch a frontend revalidation on Promotion create, but does on update/delete', function () {
    Bus::fake();
    $promotion = Promotion::factory()->create();
    Bus::assertNotDispatched(NotifyFrontendRevalidation::class);

    Bus::fake();
    $promotion->update(['name' => 'Renamed promo']);
    Bus::assertDispatched(NotifyFrontendRevalidation::class, fn ($job) => $job->tags === ["promotion:{$promotion->id}"]);

    Bus::fake();
    $promotion->delete();
    Bus::assertDispatched(NotifyFrontendRevalidation::class, fn ($job) => $job->tags === ["promotion:{$promotion->id}"]);
});

it('also dispatches the linked promo-landing ContentPage tag on Promotion update/delete', function () {
    $promotion = Promotion::factory()->create();
    $page = ContentPage::factory()->ofType(ContentPageType::PromoLanding)->create([
        'promotion_id' => $promotion->id,
        'slug' => 'spring-sale',
    ]);
    // Unrelated page — must never be included.
    ContentPage::factory()->create(['promotion_id' => null]);

    Bus::fake();
    $promotion->update(['name' => 'Renamed promo']);
    Bus::assertDispatched(NotifyFrontendRevalidation::class, fn ($job) => $job->tags === [
        "promotion:{$promotion->id}", 'content:promo_landing:spring-sale',
    ]);

    Bus::fake();
    $promotion->delete();
    Bus::assertDispatched(NotifyFrontendRevalidation::class, fn ($job) => $job->tags === [
        "promotion:{$promotion->id}", 'content:promo_landing:spring-sale',
    ]);

    expect($page->fresh()->promotion_id)->toBeNull(); // nullOnDelete FK, not the tag source for 'deleted'
});

it('dispatches every linked ContentPage tag, on both update and delete, when a Promotion has more than one', function () {
    $promotion = Promotion::factory()->create();
    ContentPage::factory()->ofType(ContentPageType::PromoLanding)->create([
        'promotion_id' => $promotion->id,
        'slug' => 'spring-sale',
    ]);
    ContentPage::factory()->ofType(ContentPageType::LocationPage)->create([
        'promotion_id' => $promotion->id,
        'slug' => 'richmond-tyres',
    ]);
    $expectedTags = [
        "promotion:{$promotion->id}", 'content:promo_landing:spring-sale', 'content:location_page:richmond-tyres',
    ];

    Bus::fake();
    $promotion->update(['name' => 'Renamed promo']);
    Bus::assertDispatched(NotifyFrontendRevalidation::class, fn ($job) => $job->tags === $expectedTags);

    Bus::fake();
    $promotion->delete();
    Bus::assertDispatched(NotifyFrontendRevalidation::class, fn ($job) => $job->tags === $expectedTags);
});
