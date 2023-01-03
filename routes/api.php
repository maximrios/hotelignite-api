<?php


use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\AccommodationController;
use App\Http\Controllers\Api\V1\BookingController;
use App\Http\Controllers\Api\V1\TourController;
use App\Http\Controllers\Api\V1\TravelAgencyController;

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

// Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
//     return $request->user();
// });
//Route::middleware('auth:api')->group(function () {
Route::middleware('client')->group(function () {
    Route::get('accommodations', [AccommodationController::class, 'index'])->name('accommodations.index');
    Route::get('accommodation', [AccommodationController::class, 'show'])->name('accommodations.show');

    Route::get('agencies', [TravelAgencyController::class, 'index'])->name('agencies.index');
    Route::get('tours', [TourController::class, 'index'])->name('tours.index');
    Route::get('tour', [TourController::class, 'show'])->name('tours.show');

    Route::post('booking', [BookingController::class, 'store'])->name('bookings.store');
    Route::put('booking', [BookingController::class, 'update'])->name('bookings.update');
    Route::get('booking', [BookingController::class, 'show'])->name('bookings.show');
});
