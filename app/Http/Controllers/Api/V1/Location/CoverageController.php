<?php

namespace App\Http\Controllers\Api\V1\Location;

use App\Enums\ContentPageType;
use App\Enums\ServiceZoneType;
use App\Http\Controllers\Controller;
use App\Models\ContentPage;
use App\Models\ServiceZone;
use App\Services\Location\CoverageService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;

/**
 * Public coverage listing: `GET /api/v1/locations` (state > city > suburbs
 * tree) and `GET /api/v1/locations/{state}/{city}` (one city). Built from the
 * existing zone/suburb tables by {@see CoverageService}; only active zones
 * with a `city_name`, and suburbs the serviceability rule assigns to them.
 */
class CoverageController extends Controller
{
    public function __construct(private readonly CoverageService $coverage) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->coverage->tree()]);
    }

    /**
     * `404` when the state/city pair is not a served city (including a real
     * state with no served city of that slug).
     */
    public function show(string $state, string $city): JsonResponse
    {
        $found = $this->coverage->city($state, $city);

        abort_if($found === null, 404);

        $cityRow = $found['city'];
        $zones = $found['zones'];

        return response()->json(['data' => [
            'state' => $found['state'],
            'city' => [
                'name' => $cityRow['name'],
                'slug' => $cityRow['slug'],
                'service_zone_id' => $cityRow['service_zone_id'],
                'service_zone_ids' => $cityRow['service_zone_ids'],
                'suburb_count' => $cityRow['suburb_count'],
            ],
            'suburbs' => $cityRow['suburbs'],
            'coverage' => [
                'zones' => $zones->map(fn (ServiceZone $zone): array => [
                    'id' => $zone->id,
                    'name' => $zone->name,
                    'type' => $zone->type->value,
                    'radius_km' => $zone->type === ServiceZoneType::Radius && $zone->radius_km !== null ? (float) $zone->radius_km : null,
                    'operating_hours' => $zone->operating_hours,
                ])->values()->all(),
                'notes' => $this->coverageNotes($cityRow['name'], $zones),
            ],
            'content' => $this->locationPageContent($found['state']['slug'], $cityRow['slug'], $cityRow['service_zone_ids']),
        ]]);
    }

    /**
     * Plain-language, data-derived notes (never authored copy).
     *
     * @param  Collection<int, ServiceZone>  $zones
     * @return list<string>
     */
    private function coverageNotes(string $cityName, Collection $zones): array
    {
        $notes = [];

        foreach ($zones as $zone) {
            $notes[] = match ($zone->type) {
                ServiceZoneType::Radius => sprintf('%s: we travel up to %s km from our %s base.', $zone->name, rtrim(rtrim(number_format((float) $zone->radius_km, 2, '.', ''), '0'), '.'), $cityName),
                ServiceZoneType::SuburbList => sprintf('%s: we service the suburbs listed for %s.', $zone->name, $cityName),
            };
        }

        $closedDays = $zones->first()?->operating_hours;

        if (is_array($closedDays)) {
            $closed = collect($closedDays)->filter(fn ($hours): bool => $hours === null)->keys()->map(fn (string $day): string => ucfirst($day))->values();

            if ($closed->isNotEmpty()) {
                $notes[] = 'Closed on '.$closed->join(', ', ' and ').'.';
            }
        }

        return $notes;
    }

    /**
     * The published `location_page` CMS row for this city: linked to one of
     * the city's zones, else slugged `{city}` or `{state}-{city}`.
     *
     * @param  list<int>  $zoneIds
     * @return array<string, mixed>|null
     */
    private function locationPageContent(string $stateSlug, string $citySlug, array $zoneIds): ?array
    {
        $page = ContentPage::query()
            ->published()
            ->where('type', ContentPageType::LocationPage)
            ->where(fn ($query) => $query
                ->whereIn('service_zone_id', $zoneIds)
                ->orWhereIn('slug', [$citySlug, "{$stateSlug}-{$citySlug}"]))
            ->orderByRaw('service_zone_id IS NULL')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();

        if ($page === null) {
            return null;
        }

        return [
            'title' => $page->title,
            'slug' => $page->slug,
            'excerpt' => $page->excerpt,
            'body' => $page->body,
            'featured_image_path' => $page->featured_image_path,
            'meta_title' => $page->meta_title,
            'meta_description' => $page->meta_description,
            'updated_at' => $page->updated_at?->toIso8601String(),
        ];
    }
}
