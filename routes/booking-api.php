<?php

use App\Http\Controllers\Api\Booking\BookingEngineController;
use Illuminate\Support\Facades\Route;

/*
| Motor de reservas: lo consume el servidor de `engine/`, con credencial de
| servicio. Ver docs/booking-engine.md.
*/
Route::middleware(['json.response', 'auth.booking', 'throttle:booking'])->prefix('{slug}')->group(function () {
    Route::get('/', [BookingEngineController::class, 'show'])->name('booking.show-hotel');
    Route::get('search', [BookingEngineController::class, 'search'])->name('booking.search');
    Route::post('holds', [BookingEngineController::class, 'hold'])->middleware('throttle:booking-hold')->name('booking.holds.store');
    Route::get('holds/{token}', [BookingEngineController::class, 'showHold'])->name('booking.holds.show');
    Route::delete('holds/{token}', [BookingEngineController::class, 'releaseHold'])->name('booking.holds.release');
    Route::post('holds/{token}/confirm', [BookingEngineController::class, 'confirm'])->middleware('throttle:booking-hold')->name('booking.holds.confirm');
});
