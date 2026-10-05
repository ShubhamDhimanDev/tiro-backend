<?php

use App\Models\Booking;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    config([
        'cache.default' => 'database',
        'queue.default' => 'database',
        'session.driver' => 'database',
    ]);
});

it('ships database defaults for cache, queue and sessions', function (): void {
    $env = file_get_contents(base_path('.env.example'));

    expect($env)->toContain('CACHE_STORE=database')
        ->toContain('QUEUE_CONNECTION=database')
        ->toContain('SESSION_DRIVER=database')
        ->toContain('APP_DEBUG=false');
});

it('acquires and releases atomic locks on the database store', function (): void {
    $lock = Cache::lock('booking-hold:test', 10);

    expect($lock->get())->toBeTrue();
    expect(Cache::lock('booking-hold:test', 10)->get())->toBeFalse();

    $lock->release();

    expect(Cache::lock('booking-hold:test', 10)->get())->toBeTrue();
});

it('round-trips the manage token cache on the database store', function (): void {
    Booking::cacheManageToken(987, 'token-abc');

    expect(Booking::cachedManageToken(987))->toBe('token-abc');
});
