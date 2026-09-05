<?php

use App\Http\Controllers\CalendarController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GmailController;
use App\Http\Controllers\PaymentMethodController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SubscriptionController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route(auth()->check() ? 'dashboard' : 'login');
});

Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::get('/calendar', [CalendarController::class, 'index'])
    ->middleware(['auth', 'verified'])
    ->name('calendar.index');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::patch('/profile/notifications', [ProfileController::class, 'updateNotificationPreferences'])->name('profile.notifications.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::resource('subscriptions', SubscriptionController::class);

    Route::post('/payment-methods', [PaymentMethodController::class, 'store'])->name('payment-methods.store');

    Route::get('/gmail/connect', [GmailController::class, 'connect'])->name('gmail.connect');
    Route::get('/gmail/callback', [GmailController::class, 'callback'])->name('gmail.callback');
    Route::delete('/gmail/disconnect', [GmailController::class, 'disconnect'])->name('gmail.disconnect');
    Route::post('/gmail/scans', [GmailController::class, 'storeScan'])->name('gmail.scans.store');
    Route::get('/gmail/scans/{scan}', [GmailController::class, 'showScan'])->name('gmail.scans.show');
    Route::get('/gmail/scans/{scan}/results', [GmailController::class, 'scanResults'])->name('gmail.scans.results');
    Route::post('/gmail/import', [GmailController::class, 'import'])->name('gmail.import');
});

require __DIR__.'/auth.php';
