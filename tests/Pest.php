<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Moved here from its original home in `StaffUserInviteTest.php` — as a
 * plain function declared inside a single test file, it was only available
 * in a given process when that specific file happened to also be part of
 * the run, which silently broke for any other test file exercising it in
 * isolation (or alongside a different subset of files). `tests/Pest.php` is
 * unconditionally loaded for every run, so this is the one place a shared
 * test helper is actually reliable. Callers still need their own
 * `beforeEach(fn () => $this->seed(RolesAndPermissionsSeeder::class))` —
 * this only creates the user/role, it doesn't seed the permission tables.
 */
function actingSuperAdmin(): User
{
    $admin = User::factory()->withTwoFactor()->create();
    $admin->assignRole('super_admin');

    return $admin;
}
