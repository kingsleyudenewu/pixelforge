<?php

use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\CreditPurchaseController;
use App\Http\Controllers\GenerationController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    // The generate studio is the signed-in home page.
    Route::get('dashboard', [GenerationController::class, 'create'])->name('dashboard');

    Route::get('generations', [GenerationController::class, 'index'])->name('generations.index');
    Route::post('generations', [GenerationController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('generations.store');

    Route::post('credits/purchases', [CreditPurchaseController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('credits.purchases.store');

    Route::get('admin/reports', [ReportController::class, 'index'])
        ->middleware('can:viewAdminReport')
        ->name('admin.reports.index');
});

require __DIR__.'/settings.php';
