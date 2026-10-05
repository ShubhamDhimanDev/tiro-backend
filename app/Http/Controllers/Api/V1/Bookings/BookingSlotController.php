<?php

namespace App\Http\Controllers\Api\V1\Bookings;

use App\Enums\VehicleFitmentPosition;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Bookings\BookingSlotsRequest;
use App\Models\ServiceZone;
use App\Services\Bookings\BookingLineItemInput;
use App\Services\Bookings\DurationCalculationService;
use App\Services\Bookings\FlexibleBookingPolicy;
use App\Services\Bookings\SlotComputationService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

/**
 * `GET /api/v1/booking-slots` — read-only, no hold created. See
 * docs/architecture/02-api-contract.md's "Booking & capacity endpoints"
 * section.
 */
class BookingSlotController extends Controller
{
    public function __construct(
        private readonly DurationCalculationService $duration,
        private readonly SlotComputationService $slots,
        private readonly FlexibleBookingPolicy $flexible,
    ) {}

    public function index(BookingSlotsRequest $request): JsonResponse
    {
        $zone = ServiceZone::query()->find($request->integer('zone'));

        abort_if($zone === null, 404);

        $items = $this->itemsFromRequest($request);
        $addons = $request->validated('addons') ?? [];

        $durationMinutes = $this->duration->calculate($items, $addons);
        $requiresAlignment = in_array('alignment', $addons, true);

        $dateFrom = CarbonImmutable::parse($request->validated('date_from'))->startOfDay();
        $dateTo = CarbonImmutable::parse($request->validated('date_to'))->startOfDay();

        $flexibleOffered = $this->flexible->isAvailable();
        $days = [];
        $anyFlexibleDay = false;

        for ($date = $dateFrom; $date->lte($dateTo); $date = $date->addDay()) {
            $slots = $this->slots->candidateSlotsForDate($zone, $date, $durationMinutes, $requiresAlignment);
            $window = $this->slots->operatingWindowStrings($zone, $date);
            $dayFlexible = $flexibleOffered && $slots !== [] && $window !== null;
            $anyFlexibleDay = $anyFlexibleDay || $dayFlexible;

            $days[] = [
                'date' => $date->toDateString(),
                'slots' => $slots,
                // A flexible booking is only offered on a day that still has
                // at least one real, assignable slot — see
                // FlexibleBookingPolicy for the capacity rule.
                'flexible' => [
                    'available' => $dayFlexible,
                    'window_start' => $dayFlexible ? $window['start'] : null,
                    'window_end' => $dayFlexible ? $window['end'] : null,
                ],
            ];
        }

        return response()->json([
            'data' => [
                'duration_minutes' => $durationMinutes,
                'flexible' => [
                    'available' => $anyFlexibleDay,
                    'discount_cents' => $flexibleOffered ? $this->flexible->discountCents() : 0,
                    'label' => $flexibleOffered ? $this->flexible->label() : null,
                ],
                'days' => $days,
            ],
        ]);
    }

    /**
     * @return Collection<int, BookingLineItemInput>
     */
    private function itemsFromRequest(BookingSlotsRequest $request): Collection
    {
        /** @var list<array{tyre_variant_id: int|string, quantity: int|string, position: string}> $itemsInput */
        $itemsInput = $request->validated('items') ?? [];

        return collect($itemsInput)
            ->map(fn (array $item): BookingLineItemInput => new BookingLineItemInput(
                tyreVariantId: (int) $item['tyre_variant_id'],
                quantity: (int) $item['quantity'],
                position: VehicleFitmentPosition::from($item['position']),
            ));
    }
}
