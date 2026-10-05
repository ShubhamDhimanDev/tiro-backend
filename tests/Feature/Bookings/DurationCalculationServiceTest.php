<?php

use App\Enums\TyreCategory;
use App\Enums\VehicleFitmentPosition;
use App\Exceptions\Bookings\MissingDurationRuleException;
use App\Models\TyreModel;
use App\Models\TyreVariant;
use App\Services\Bookings\BookingLineItemInput;
use App\Services\Bookings\DurationCalculationService;

/**
 * Exercises the exact formula from
 * docs/architecture/04-booking-capacity-engine.md's "Job duration" section.
 */
function makeTyreVariant(TyreCategory $category, bool $runFlat = false): TyreVariant
{
    $model = TyreModel::factory()->create(['category' => $category, 'run_flat' => $runFlat]);

    return TyreVariant::factory()->create(['tyre_model_id' => $model->id]);
}

it('applies the base setup overhead even with an empty cart', function () {
    seedDurationRules();

    $minutes = app(DurationCalculationService::class)->calculate(collect(), []);

    expect($minutes)->toBe(15);
});

it('sums per-tyre-category minutes across line items, scaled by quantity', function () {
    seedDurationRules();

    $carVariant = makeTyreVariant(TyreCategory::Car);
    $suvVariant = makeTyreVariant(TyreCategory::Suv);

    $items = collect([
        new BookingLineItemInput($carVariant->id, 4, VehicleFitmentPosition::All),
        new BookingLineItemInput($suvVariant->id, 2, VehicleFitmentPosition::All),
    ]);

    $minutes = app(DurationCalculationService::class)->calculate($items, []);

    // base(15) + car(10*4=40) + suv(12*2=24)
    expect($minutes)->toBe(15 + 40 + 24);
});

it('adds the per-tyre-unit run-flat addon only for run-flat line items, derived from the product', function () {
    seedDurationRules();

    $runFlatVariant = makeTyreVariant(TyreCategory::Car, runFlat: true);

    $items = collect([
        new BookingLineItemInput($runFlatVariant->id, 2, VehicleFitmentPosition::All),
    ]);

    $minutes = app(DurationCalculationService::class)->calculate($items, []);

    // base(15) + car(10*2=20) + run_flat(5*2=10)
    expect($minutes)->toBe(15 + 20 + 10);
});

it('adds once-per-booking minutes for customer-selected addons regardless of quantity', function () {
    seedDurationRules();

    $variant = makeTyreVariant(TyreCategory::Car);

    $items = collect([
        new BookingLineItemInput($variant->id, 4, VehicleFitmentPosition::All),
    ]);

    $minutes = app(DurationCalculationService::class)->calculate($items, ['alignment', 'locking_nuts']);

    // base(15) + car(10*4=40) + alignment(20) + locking_nuts(5)
    expect($minutes)->toBe(15 + 40 + 20 + 5);
});

it('adds the staggered addon once when line items span more than one position, never when they do not', function () {
    seedDurationRules();

    $front = makeTyreVariant(TyreCategory::Car);
    $rear = makeTyreVariant(TyreCategory::Car);

    $staggeredItems = collect([
        new BookingLineItemInput($front->id, 2, VehicleFitmentPosition::Front),
        new BookingLineItemInput($rear->id, 2, VehicleFitmentPosition::Rear),
    ]);

    $nonStaggeredItems = collect([
        new BookingLineItemInput($front->id, 4, VehicleFitmentPosition::All),
    ]);

    $duration = app(DurationCalculationService::class);

    // base(15) + car(10*2 + 10*2=40) + staggered(10)
    expect($duration->calculate($staggeredItems, []))->toBe(15 + 40 + 10);
    // base(15) + car(10*4=40), no staggered addon for a single-position cart
    expect($duration->calculate($nonStaggeredItems, []))->toBe(15 + 40);
});

it('throws a hard error, never a silent 0, for a missing DurationRule combination', function () {
    // Deliberately does not seed tyre_addon:run_flat.
    seedDurationRules(['tyre_addon:run_flat' => null]);

    $variant = makeTyreVariant(TyreCategory::Car, runFlat: true);

    $items = collect([new BookingLineItemInput($variant->id, 1, VehicleFitmentPosition::All)]);

    app(DurationCalculationService::class)->calculate($items, []);
})->throws(MissingDurationRuleException::class);
