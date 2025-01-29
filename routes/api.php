<?php


use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\AccommodationController;
use App\Http\Controllers\Api\V1\AccommodationTypeController;
use App\Http\Controllers\Api\V1\BookingController;
use App\Http\Controllers\Api\V1\ChannelController;
use App\Http\Controllers\Api\V1\CityController;
use App\Http\Controllers\Api\V1\ReservationController;
use App\Http\Controllers\Api\V1\ServiceController;
use App\Http\Controllers\Api\V1\TourController;
use App\Http\Controllers\Api\V1\TravelAgencyController;
use App\Http\Controllers\Api\V1\UserAuthController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::post('/auth/login', [UserAuthController::class, 'login']);

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::get('v1/channels', [ChannelController::class, 'index'])->name('channels.index');

Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('v1')->group(function () {
        Route::get('accommodations', [AccommodationController::class, 'index'])->name('accommodations.index');
        //Route::get('accommodation', [AccommodationController::class, 'show'])->name('accommodations.show');
        Route::get('accommodation/{slug}', [AccommodationController::class, 'show'])->name('accommodations.show');
        Route::put('accommodations/{id}', [AccommodationController::class, 'update'])->name('accommodations.update');
        Route::post('accommodations', [AccommodationController::class, 'store'])->name('accommodations.store');
        Route::delete('accommodations', [AccommodationController::class, 'destroy'])->name('accommodations.destroy');

        Route::get('accommodations/types', [AccommodationTypeController::class, 'index'])
            ->name('accommodations.types.index');
        Route::get('services', [ServiceController::class, 'index'])->name('services.index');

        Route::get('agencies', [TravelAgencyController::class, 'index'])->name('agencies.index');
        Route::get('tours', [TourController::class, 'index'])->name('tours.index');
        Route::get('tour', [TourController::class, 'show'])->name('tours.show');
        Route::get('cities', [CityController::class, 'index'])->name('cities.index');

        Route::apiResource('channels', ChannelController::class);

        Route::post('booking', [BookingController::class, 'store'])->name('booking.store');
        Route::put('booking', [BookingController::class, 'update'])->name('booking.update');
        Route::get('booking', [BookingController::class, 'show'])->name('booking.show');
        Route::get('bookings', [BookingController::class, 'index'])->name('bookings.index');

        Route::get('reservations', [ReservationController::class, 'index'])->name('reservations.index');
    });
});
