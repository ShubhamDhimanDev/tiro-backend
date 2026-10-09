<?php

namespace App\Http\Controllers\Api\V1\Bookings;

use App\Enums\DurationRuleAppliesTo;
use App\Enums\VehicleFitmentPosition;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Bookings\BookingAvailabilityRequest;
use App\Models\DurationRule;
use App\Models\ServiceZone;
use App\Models\Suburb;
use App\Services\Bookings\BookingLineItemInput;
use App\Services\Bookings\DurationCalculationService;
use App\Services\Bookings\FlexibleBookingPolicy;
use App\Services\Bookings\SlotComputationService;
use App\Services\Location\ServiceabilityResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/**
 * `GET /api/v1/booking-availability` — the checkout/PDP week strip in one
 * call: resolves a suburb/postcode to its service zone (same resolver as
 * `POST /serviceability`) and returns each day's slots grouped into the
 * Morning/Lunch/Afternoon windows, plus flexible-discount and "last slot"
 * markers. Built on the same {@see SlotComputationService} as
 * `GET /booking-slots`, so it never offers a time `POST /bookings` cannot take.
 */
class BookingAvailabilityController extends Controller
{
    /** Window key => [label, from minutes (inclusive), to minutes (exclusive), range label template]. */
    private const WINDOWS = [
        'morning' => ['Morning', 0, 660],
        'lunch' => ['Lunch', 660, 840],
        'afternoon' => ['Afternoon', 840, 1440],
    ];

    public function __construct(
        private readonly ServiceabilityResolver $resolver,
        private readonly DurationCalculationService $duration,
        private readonly SlotComputationService $slots,
        private readonly FlexibleBookingPolicy $flexible,
    ) {}

    public function index(BookingAvailabilityRequest $request): JsonResponse
    {
        $zone = $this->resolveZone($request);

        if ($zone === null) {
            return response()->json(['data' => [
                'serviceable' => false,
                'zone' => null,
                'duration_minutes' => 0,
                'flexible' => ['available' => false, 'discount_cents' => 0, 'label' => null],
                'days' => [],
            ]]);
        }

        $quantity = $request->integer('quantity', 4);
        $durationMinutes = $this->durationMinutes($request, $quantity);

        $from = CarbonImmutable::parse($request->validated('date_from'))->startOfDay();
        $to = CarbonImmutable::parse($request->validated('date_to'))->startOfDay();
        $today = CarbonImmutable::today();
        $flexibleOffered = $this->flexible->isAvailable();
        $anyFlexibleDay = false;
        $days = [];

        for ($date = $from; $date->lte($to); $date = $date->addDay()) {
            $slots = $date->lte($today) ? [] : $this->slots->candidateSlotsForDate($zone, $date, $durationMinutes, false);
            $window = $this->slots->operatingWindowStrings($zone, $date);
            $dayFlexible = $flexibleOffered && $slots !== [] && $window !== null;
            $anyFlexibleDay = $anyFlexibleDay || $dayFlexible;

            $days[] = [
                'date' => $date->toDateString(),
                'available' => $slots !== [],
                'slots_left' => count($slots),
                'last_slot' => count($slots) === 1,
                'flexible_available' => $dayFlexible,
                'flexible_window' => $dayFlexible ? $window : null,
                'windows' => $this->windows($slots),
                'slots' => $slots,
            ];
        }

        return response()->json(['data' => [
            'serviceable' => true,
            'zone' => ['id' => $zone->id, 'name' => $zone->name],
            'duration_minutes' => $durationMinutes,
            'flexible' => [
                'available' => $anyFlexibleDay,
                'discount_cents' => $flexibleOffered ? $this->flexible->discountCents() : 0,
                'label' => $flexibleOffered ? $this->flexible->label() : null,
            ],
            'days' => $days,
        ]]);
    }

    private function resolveZone(BookingAvailabilityRequest $request): ?ServiceZone
    {
        if ($request->filled('zone')) {
            return ServiceZone::query()->serviceable()->find($request->integer('zone'));
        }

        $suburbs = $request->filled('postcode')
            ? Suburb::query()->where('postcode', $request->validated('postcode'))->get()
            : Suburb::query()->whereRaw('LOWER(name) = ?', [Str::lower((string) $request->validated('suburb'))])->get();

        return $this->resolver->resolve($suburbs);
    }

    private function durationMinutes(BookingAvailabilityRequest $request, int $quantity): int
    {
        if ($request->filled('tyre_variant_id')) {
            return $this->duration->calculate(
                collect([new BookingLineItemInput($request->integer('tyre_variant_id'), $quantity, VehicleFitmentPosition::All)]),
                [],
            );
        }

        return DurationRule::minutesFor(DurationRuleAppliesTo::Base, 'setup_overhead')
            + DurationRule::minutesFor(DurationRuleAppliesTo::TyreCategory, 'car') * $quantity;
    }

    /**
     * @param  list<array{start: string, end: string}>  $slots
     * @return array<string, array{label: string, range: string, available: bool, slots: list<array{start: string, end: string}>}>
     */
    private function windows(array $slots): array
    {
        $windows = [];

        foreach (self::WINDOWS as $key => [$label, $fromMinutes, $toMinutes]) {
            $inWindow = array_values(array_filter($slots, function (array $slot) use ($fromMinutes, $toMinutes): bool {
                [$hours, $minutes] = array_map('intval', explode(':', $slot['start']));
                $start = $hours * 60 + $minutes;

                return $start >= $fromMinutes && $start < $toMinutes;
            }));

            $windows[$key] = [
                'label' => $label,
                'range' => match ($key) {
                    'morning' => 'Before 11am',
                    'lunch' => '11am to 2pm',
                    default => 'From 2pm',
                },
                'available' => $inWindow !== [],
                'slots' => $inWindow,
            ];
        }

        return $windows;
    }
}
