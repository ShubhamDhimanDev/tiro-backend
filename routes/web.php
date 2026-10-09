<?php

use App\Http\Controllers\Admin\DashboardController;
use Illuminate\Support\Facades\Route;

// There is no public site here (the storefront is a separate app): the root sends staff
// to the dashboard, and the `auth` middleware bounces guests on to the login page.
Route::redirect('/', '/admin/dashboard')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('admin/dashboard', DashboardController::class)->name('dashboard');

    // Old landing URL — kept so bookmarks and stale links still resolve.
    Route::redirect('dashboard', '/admin/dashboard');
});

require __DIR__.'/settings.php';
require __DIR__.'/admin.php';
