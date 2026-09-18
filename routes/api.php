<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Catalogue\BrandController;
use App\Http\Controllers\Api\V1\Catalogue\TyreController;
use App\Http\Controllers\Api\V1\Location\ServiceabilityController;
use App\Http\Controllers\Api\V1\Vehicles\VehicleController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/auth')->name('api.v1.auth.')->group(function () {
    Route::post('register', [AuthController::class, 'register'])
        ->middleware('throttle:otp-request-registration')
        ->name('register');

    Route::post('register/verify', [AuthController::class, 'verifyRegistration'])
        ->middleware('throttle:otp-verify-registration')
        ->name('register.verify');

    Route::post('login', [AuthController::class, 'login'])
        ->name('login');

    Route::post('otp/request', [AuthController::class, 'requestOtp'])
        ->middleware('throttle:otp-request-login')
        ->name('otp.request');

    Route::post('otp/verify', [AuthController::class, 'verifyOtp'])
        ->middleware('throttle:otp-verify-login')
        ->name('otp.verify');

    Route::post('password/reset/request', [AuthController::class, 'requestPasswordReset'])
        ->middleware('throttle:otp-request-password_reset')
        ->name('password.reset.request');

    Route::post('password/reset/verify', [AuthController::class, 'verifyPasswordReset'])
        ->middleware('throttle:otp-verify-password_reset')
        ->name('password.reset.verify');

    Route::middleware('auth:customer')->group(function () {
        Route::delete('session', [AuthController::class, 'destroySession'])->name('session.destroy');
        Route::delete('sessions', [AuthController::class, 'destroyAllSessions'])->name('sessions.destroy');
    });
});

Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::post('serviceability', [ServiceabilityController::class, 'check'])->name('serviceability.check');

    // Order matters: the literal `popular-sizes`/`latest-releases` segments
    // must be registered before the `{slug}` wildcard routes, or they'd be
    // captured as a slug lookup instead.
    Route::prefix('tyres')->name('tyres.')->group(function () {
        Route::get('popular-sizes', [TyreController::class, 'popularSizes'])->name('popular-sizes');
        Route::get('latest-releases', [TyreController::class, 'latestReleases'])->name('latest-releases');
        Route::get('{slug}/availability', [TyreController::class, 'availability'])->name('availability');
        Route::get('{slug}', [TyreController::class, 'show'])->name('show');
        Route::get('/', [TyreController::class, 'index'])->name('index');
    });

    Route::get('brands', [BrandController::class, 'index'])->name('brands.index');

    // Order matters here too: the literal `makes`/`models`/`years` segments
    // must be registered before the `{vehicle}` wildcard route.
    Route::prefix('vehicles')->name('vehicles.')->group(function () {
        Route::get('makes', [VehicleController::class, 'makes'])->name('makes');
        Route::get('models', [VehicleController::class, 'models'])->name('models');
        Route::get('years', [VehicleController::class, 'years'])->name('years');
        Route::get('{vehicle}/fitment', [VehicleController::class, 'fitment'])->name('fitment');
    });
});
