<?php

use App\Enums\VehicleFitmentPosition;
use App\Services\Vehicles\FitmentSetValidator;

/**
 * {@see FitmentSetValidator} enforces the `is_staggered`/`position`
 * agreement invariant shared by the importer and (in a later round) admin
 * CRUD writes — see docs/architecture/01-data-model.md.
 */
beforeEach(function () {
    $this->validator = new FitmentSetValidator;
});

it('accepts an empty set', function () {
    expect($this->validator->validate([]))->toBe([]);
});

it('accepts a single all-position row with is_staggered=false', function () {
    $errors = $this->validator->validate([
        ['position' => VehicleFitmentPosition::All, 'is_staggered' => false],
    ]);

    expect($errors)->toBe([]);
});

it('accepts front+rear rows with is_staggered=true', function () {
    $errors = $this->validator->validate([
        ['position' => VehicleFitmentPosition::Front, 'is_staggered' => true],
        ['position' => VehicleFitmentPosition::Rear, 'is_staggered' => true],
    ]);

    expect($errors)->toBe([]);
});

it('rejects position=all combined with is_staggered=true', function () {
    $errors = $this->validator->validate([
        ['position' => VehicleFitmentPosition::All, 'is_staggered' => true],
    ]);

    expect($errors)->not->toBe([]);
});

it('rejects a staggered set missing the rear row', function () {
    $errors = $this->validator->validate([
        ['position' => VehicleFitmentPosition::Front, 'is_staggered' => true],
    ]);

    expect($errors)->not->toBe([]);
});

it('rejects a staggered set with a duplicate position', function () {
    $errors = $this->validator->validate([
        ['position' => VehicleFitmentPosition::Front, 'is_staggered' => true],
        ['position' => VehicleFitmentPosition::Front, 'is_staggered' => true],
    ]);

    expect($errors)->not->toBe([]);
});

it('rejects a non-staggered set with more than one row', function () {
    $errors = $this->validator->validate([
        ['position' => VehicleFitmentPosition::All, 'is_staggered' => false],
        ['position' => VehicleFitmentPosition::All, 'is_staggered' => false],
    ]);

    expect($errors)->not->toBe([]);
});

it('rejects rows that disagree on is_staggered', function () {
    $errors = $this->validator->validate([
        ['position' => VehicleFitmentPosition::Front, 'is_staggered' => true],
        ['position' => VehicleFitmentPosition::Rear, 'is_staggered' => false],
    ]);

    expect($errors)->not->toBe([]);
});
