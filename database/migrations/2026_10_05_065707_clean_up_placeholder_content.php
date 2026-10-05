<?php

use App\Support\ContentPlaceholderResolver;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * One-off, idempotent data clean-up for rows seeded before the launch-copy
 * fixes: unresolved `[TOKEN]` placeholders, the "Richmond" copy on the
 * Melbourne location page, "Use code ..." promo wording, and "seeded
 * dev/test"/"placeholder" boilerplate in customer-visible fields. Uses the
 * query builder (no model events, so no revalidation webhooks fire).
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->resolveContentTokens();
        $this->fixLocationPages();
        $this->fixPromotionCopy();
        $this->fixTyreModelDescriptions();
    }

    public function down(): void
    {
        // Data clean-up only; nothing to reverse.
    }

    private function resolveContentTokens(): void
    {
        DB::table('content_pages')->get(['id', 'body', 'excerpt'])->each(function (object $row): void {
            $body = ContentPlaceholderResolver::resolve((string) $row->body);
            $excerpt = $row->excerpt === null ? null : ContentPlaceholderResolver::resolve($row->excerpt);

            if ($body !== $row->body || $excerpt !== $row->excerpt) {
                DB::table('content_pages')->where('id', $row->id)->update(['body' => $body, 'excerpt' => $excerpt]);
            }
        });

        DB::table('faqs')->get(['id', 'answer'])->each(function (object $row): void {
            $answer = ContentPlaceholderResolver::resolve((string) $row->answer);

            if ($answer !== $row->answer) {
                DB::table('faqs')->where('id', $row->id)->update(['answer' => $answer]);
            }
        });
    }

    /**
     * A location page linked to a city's service zone must talk about that
     * city, not whichever suburb it was first authored for.
     */
    private function fixLocationPages(): void
    {
        $pages = DB::table('content_pages')
            ->join('service_zones', 'service_zones.id', '=', 'content_pages.service_zone_id')
            ->join('states', 'states.id', '=', 'service_zones.state_id')
            ->where('content_pages.type', 'location_page')
            ->where('content_pages.status', 'published')
            ->whereNotNull('service_zones.city_name')
            ->select('content_pages.id', 'content_pages.slug', 'content_pages.title', 'content_pages.excerpt', 'content_pages.body', 'service_zones.city_name', 'states.code as state_code')
            ->get();

        foreach ($pages as $page) {
            $mentionsOtherSuburb = Str::contains($page->title.$page->excerpt.$page->body, 'Richmond')
                && $page->city_name !== 'Richmond';

            if (! $mentionsOtherSuburb) {
                continue;
            }

            $slug = Str::slug($page->city_name.' '.$page->state_code);
            $slugTaken = DB::table('content_pages')
                ->where('type', 'location_page')->where('slug', $slug)->where('id', '!=', $page->id)->exists();

            DB::table('content_pages')->where('id', $page->id)->update([
                'slug' => $slugTaken ? $page->slug : $slug,
                'title' => "Mobile Tyre Fitting in {$page->city_name}, {$page->state_code}",
                'excerpt' => "Mobile tyre fitting across {$page->city_name} and surrounding suburbs.",
                'body' => "<p>Tiro's technicians come to you across {$page->city_name} and surrounding suburbs. Book a fitting at your home or workplace.</p>",
            ]);
        }
    }

    private function fixPromotionCopy(): void
    {
        DB::table('promotions')->get(['id', 'summary', 'terms'])->each(function (object $row): void {
            $summary = $row->summary;
            $terms = $row->terms;

            if ($summary !== null) {
                $summary = preg_replace('/\s*Use (?:the )?code [A-Z0-9_-]+ (?:at the cart|at checkout)\.?/i', ' Applied automatically.', $summary);
                $summary = preg_replace('/New to Tiro\?\s*Use (?:the )?code(?: (?-i:[A-Z0-9_-]{4,}))?(?: at checkout)?(?: for)? ?/i', 'New to Tiro? Enjoy ', $summary);
                $summary = trim((string) $summary);
            }

            if ($terms !== null) {
                $terms = preg_replace('/^(?:Demo|Seed) placeholder terms\.\s*(?:Replace before launch\.\s*)?/i', '', $terms);
            }

            if ($summary !== $row->summary || $terms !== $row->terms) {
                DB::table('promotions')->where('id', $row->id)->update(['summary' => $summary, 'terms' => $terms]);
            }
        });
    }

    private function fixTyreModelDescriptions(): void
    {
        DB::table('tyre_models')
            ->where('description', 'like', '%seeded dev/test catalogue data%')
            ->join('brands', 'brands.id', '=', 'tyre_models.brand_id')
            ->select('tyre_models.id', 'tyre_models.name', 'tyre_models.tyre_type', 'tyre_models.category', 'brands.name as brand_name')
            ->get()
            ->each(function (object $row): void {
                DB::table('tyre_models')->where('id', $row->id)->update([
                    'description' => "The {$row->brand_name} {$row->name} is a ".str_replace('_', ' ', $row->tyre_type).' tyre for '.str_replace('_', ' ', $row->category).' vehicles, supplied and fitted at your door.',
                ]);
            });
    }
};
