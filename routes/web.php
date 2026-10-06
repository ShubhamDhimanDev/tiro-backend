<?php

use App\Http\Controllers\Admin\DashboardController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('admin/dashboard', DashboardController::class)->name('dashboard');

    // Old landing URL — kept so bookmarks and stale links still resolve.
    Route::redirect('dashboard', '/admin/dashboard');
});

require __DIR__.'/settings.php';
require __DIR__.'/admin.php';
