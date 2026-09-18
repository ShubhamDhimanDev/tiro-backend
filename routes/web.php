<?php

use App\Http\Middleware\EnsureTwoFactorEnabled;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified', EnsureTwoFactorEnabled::class])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
require __DIR__.'/admin.php';
