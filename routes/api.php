<?php

use App\Http\Controllers\Api\Admin\V1\AccommodationController;
use App\Http\Controllers\Api\Admin\V1\AccommodationImageController;
use App\Http\Controllers\Api\Admin\V1\AccommodationServiceController;
use App\Http\Controllers\Api\Admin\V1\AccommodationTypeController;
use App\Http\Controllers\Api\Admin\V1\ServiceController;
use App\Http\Controllers\Api\V1\AccommodationAvailabilityController;
use App\Http\Controllers\Api\V1\AccommodationController as WebAccommodationController;
use App\Http\Controllers\Api\V1\AccommodationDescriptionController;
use App\Http\Controllers\Api\V1\AccommodationPolicyController;
use App\Http\Controllers\Api\V1\AccommodationPolicyOldController;
use App\Http\Controllers\Api\V1\AccommodationPolicyTranslationController;
use App\Http\Controllers\Api\V1\AccommodationRatePolicyController;
use App\Http\Controllers\Api\V1\AccommodationServiceController as WebAccommodationServiceController;
use App\Http\Controllers\Api\V1\AccommodationTypeController as WebAccommodationTypeController;
use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\AccountTypeController;
use App\Http\Controllers\Api\V1\BookingController;
use App\Http\Controllers\Api\V1\ChannelController;
use App\Http\Controllers\Api\V1\CityController;
use App\Http\Controllers\Api\V1\InquiryController;
use App\Http\Controllers\Api\V1\PlanController;
use App\Http\Controllers\Api\V1\PolicyController;
use App\Http\Controllers\Api\V1\RateController;
use App\Http\Controllers\Api\V1\RatePlanController;
use App\Http\Controllers\Api\V1\ReservationController;
use App\Http\Controllers\Api\V1\RoomAvailabilityController;
use App\Http\Controllers\Api\V1\RoomController;
use App\Http\Controllers\Api\V1\RoomTypeBedController;
use App\Http\Controllers\Api\V1\RoomTypeController;
use App\Http\Controllers\Api\V1\RoomTypeDescriptionController;
use App\Http\Controllers\Api\V1\RoomTypeServiceController;
use App\Http\Controllers\Api\V1\TourController;
use App\Http\Controllers\Api\V1\TravelAgencyController;
use App\Http\Controllers\Api\V1\UserAuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

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

Route::post('/auth/login', [UserAuthController::class, 'login'])->middleware('throttle:login');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });
    Route::patch('/user', [UserAuthController::class, 'updateProfile']);
    Route::put('/user/password', [UserAuthController::class, 'updatePassword']);
});

Route::get('v1/channels', [ChannelController::class, 'index'])->middleware('throttle:api')->name('channels.index');

Route::middleware(['auth:sanctum', 'throttle:api', 'client.readonly'])->group(function () {
    Route::prefix('admin')->group(function () {
        Route::prefix('v1')->group(function () {
            Route::get('accommodations', [AccommodationController::class, 'index'])->name('admin.accommodations.index');
            Route::get('accommodations/{accommodation}', [AccommodationController::class, 'show'])->name('admin.accommodations.show');
            Route::post('accommodations', [AccommodationController::class, 'store'])->name('admin.accommodations.store');
            Route::put('accommodations/{accommodation}', [AccommodationController::class, 'update'])->name('admin.accommodations.update');
            Route::patch('accommodations/{accommodation}', [AccommodationController::class, 'partialUpdate'])->name('admin.accommodations.partial-update');
            Route::delete('accommodations/{accommodation}', [AccommodationController::class, 'destroy'])->name('admin.accommodations.destroy');

            Route::get('services', [ServiceController::class, 'index'])->name('admin.services.index');
            Route::get('services/{service}', [ServiceController::class, 'show'])->name('admin.services.show');

            Route::get('accommodation-types', [AccommodationTypeController::class, 'index'])->name('admin.accommodation-types.index');
            Route::get('accommodation-types/{type}', [AccommodationTypeController::class, 'show'])->name('admin.accommodation-types.show');
            Route::post('accommodation-types', [AccommodationTypeController::class, 'store'])->name('admin.accommodation-types.store');
            Route::put('accommodation-types/{type}', [AccommodationTypeController::class, 'update'])->name('admin.accommodation-types.update');
            Route::delete('accommodation-types/{type}', [AccommodationTypeController::class, 'destroy'])->name('admin.accommodation-types.destroy');

            Route::get('accommodations/{accommodation}/services', [AccommodationServiceController::class, 'index'])->name('admin.accommodations.services.index');
            Route::post('accommodations/{accommodation}/services', [AccommodationServiceController::class, 'store'])->name('admin.accommodations.services.store');
            Route::put('accommodations/{accommodation}/services', [AccommodationServiceController::class, 'update'])->name('admin.accommodations.services.sync');
            Route::delete('accommodations/{accommodation}/services/{service}', [AccommodationServiceController::class, 'destroy'])->name('admin.accommodations.services.destroy');

            Route::get('accommodations/{accommodation}/images', [AccommodationImageController::class, 'index'])->name('admin.accommodations.images.index');
            Route::post('accommodations/{accommodation}/images', [AccommodationImageController::class, 'store'])->name('admin.accommodations.images.store');
            Route::patch('accommodations/{accommodation}/images/reorder', [AccommodationImageController::class, 'reorder'])->name('admin.accommodations.images.reorder');
            Route::put('accommodations/{accommodation}/images/{image}', [AccommodationImageController::class, 'update'])->name('admin.accommodations.images.update');
            Route::delete('accommodations/{accommodation}/images/{image}', [AccommodationImageController::class, 'destroy'])->name('admin.accommodations.images.destroy');
        });
    });

    Route::prefix('v1')->group(function () {

        Route::get('accommodations', [WebAccommodationController::class, 'index'])->name('accommodations.index');
        //Route::get('accommodation', [AccommodationController::class, 'show'])->name('accommodations.show');
        Route::get('accommodation/{slug}', [AccommodationController::class, 'show'])->name('accommodations.show');
        Route::put('accommodations/{id}', [AccommodationController::class, 'update'])->name('accommodations.update');
        Route::post('accommodations', [AccommodationController::class, 'store'])->name('accommodations.store');
        Route::delete('accommodations', [AccommodationController::class, 'destroy'])->name('accommodations.destroy');

        Route::get('accommodations/types', [WebAccommodationTypeController::class, 'index'])
            ->name('accommodations.types.index');
        Route::get('accommodations/{id}/services', [AccommodationController::class, 'services'])
            ->name('accommodations.services');
        Route::get('accommodations/{id}/policies', [AccommodationController::class, 'policies'])
            ->name('accommodations.policies');
        Route::get('accommodations/{id}/availability', [AccommodationAvailabilityController::class, 'check'])
            ->name('accommodations.availability');
        Route::get('accommodation-services', [WebAccommodationServiceController::class, 'index'])->name('accommodation-services.index');
        Route::get('accommodation-services/{id}', [WebAccommodationServiceController::class, 'show'])->name('accommodation-services.show');
        Route::put('accommodations/{id}/services', [WebAccommodationServiceController::class, 'update'])->name('accommodation-services.update');
        Route::post('accommodation-services', [WebAccommodationServiceController::class, 'store'])->name('accommodation-services.store');
        Route::delete('accommodation-services', [WebAccommodationServiceController::class, 'destroy'])->name('accommodation-services.destroy');

        Route::get('accommodation-policy-links', [AccommodationPolicyOldController::class, 'index'])->name('accommodation-policy-links.index');
        Route::get('accommodation-policy-links/{id}', [AccommodationPolicyOldController::class, 'show'])->name('accommodation-policy-links.show');
        Route::put('accommodations/{id}/policy-links', [AccommodationPolicyOldController::class, 'update'])->name('accommodation-policy-links.update');
        Route::post('accommodation-policy-links', [AccommodationPolicyOldController::class, 'store'])->name('accommodation-policy-links.store');
        Route::delete('accommodation-policy-links', [AccommodationPolicyOldController::class, 'destroy'])->name('accommodation-policy-links.destroy');

        Route::get('accommodation-policies', [AccommodationPolicyController::class, 'index'])->name('accommodation-policies.index');
        Route::get('accommodation-policies/{id}', [AccommodationPolicyController::class, 'show'])->name('accommodation-policies.show');
        Route::post('accommodation-policies', [AccommodationPolicyController::class, 'store'])->name('accommodation-policies.store');
        Route::put('accommodation-policies/{id}', [AccommodationPolicyController::class, 'update'])->name('accommodation-policies.update');
        Route::delete('accommodation-policies', [AccommodationPolicyController::class, 'destroy'])->name('accommodation-policies.destroy');

        Route::get('accommodation-policy-translations', [AccommodationPolicyTranslationController::class, 'index'])->name('accommodation-policy-translations.index');
        Route::get('accommodation-policy-translations/{id}', [AccommodationPolicyTranslationController::class, 'show'])->name('accommodation-policy-translations.show');
        Route::post('accommodation-policy-translations', [AccommodationPolicyTranslationController::class, 'store'])->name('accommodation-policy-translations.store');
        Route::put('accommodation-policy-translations/{id}', [AccommodationPolicyTranslationController::class, 'update'])->name('accommodation-policy-translations.update');
        Route::delete('accommodation-policy-translations', [AccommodationPolicyTranslationController::class, 'destroy'])->name('accommodation-policy-translations.destroy');

        Route::get('accommodation-rate-policies', [AccommodationRatePolicyController::class, 'index'])->name('accommodation-rate-policies.index');
        Route::get('accommodation-rate-policies/{id}', [AccommodationRatePolicyController::class, 'show'])->name('accommodation-rate-policies.show');
        Route::post('accommodation-rate-policies', [AccommodationRatePolicyController::class, 'store'])->name('accommodation-rate-policies.store');
        Route::put('accommodation-rate-policies/{id}', [AccommodationRatePolicyController::class, 'update'])->name('accommodation-rate-policies.update');
        Route::delete('accommodation-rate-policies', [AccommodationRatePolicyController::class, 'destroy'])->name('accommodation-rate-policies.destroy');

        Route::get('services', [ServiceController::class, 'index'])->name('services.index');
        Route::get('services/{id}', [ServiceController::class, 'show'])->name('services.show');
        Route::put('services/{id}', [ServiceController::class, 'update'])->name('services.update');
        Route::post('services', [ServiceController::class, 'store'])->name('services.store');
        Route::delete('services', [ServiceController::class, 'destroy'])->name('services.destroy');

        Route::get('policies', [PolicyController::class, 'index'])->name('policies.index');
        Route::get('policies/{id}', [PolicyController::class, 'show'])->name('policies.show');
        Route::put('policies/{id}', [PolicyController::class, 'update'])->name('policies.update');
        Route::post('policies', [PolicyController::class, 'store'])->name('policies.store');
        Route::delete('policies', [PolicyController::class, 'destroy'])->name('policies.destroy');

        Route::get('agencies', [TravelAgencyController::class, 'index'])->name('agencies.index');
        Route::get('tours', [TourController::class, 'index'])->name('tours.index');
        Route::get('tour', [TourController::class, 'show'])->name('tours.show');
        Route::get('cities', [CityController::class, 'index'])->name('cities.index');
        Route::get('cities/{slug}', [CityController::class, 'show'])->name('cities.show');

        Route::post('inquiries', [InquiryController::class, 'store'])->name('inquiries.store');

        Route::apiResource('channels', ChannelController::class);

        Route::post('booking', [BookingController::class, 'store'])->name('booking.store');
        Route::put('booking', [BookingController::class, 'update'])->name('booking.update');
        Route::get('booking', [BookingController::class, 'show'])->name('booking.show');
        Route::get('bookings', [BookingController::class, 'index'])->name('bookings.index');

        Route::get('reservations', [ReservationController::class, 'index'])->name('reservations.index');

        Route::get('rooms', [RoomController::class, 'index'])->name('rooms.index');
        Route::get('rooms/{id}', [RoomController::class, 'show'])->name('rooms.show');
        Route::post('rooms', [RoomController::class, 'store'])->name('rooms.store');
        Route::put('rooms/{id}', [RoomController::class, 'update'])->name('rooms.update');
        Route::delete('rooms', [RoomController::class, 'destroy'])->name('rooms.destroy');

        Route::get('room-types', [RoomTypeController::class, 'index'])->name('room-types.index');
        Route::get('room-types/{id}', [RoomTypeController::class, 'show'])->name('room-types.show');
        Route::put('room-types/{id}', [RoomTypeController::class, 'update'])->name('room-types.update');
        Route::post('room-types', [RoomTypeController::class, 'store'])->name('room-types.store');
        Route::delete('room-types', [RoomTypeController::class, 'destroy'])->name('room-types.destroy');

        Route::get('room-type-descriptions', [RoomTypeDescriptionController::class, 'index'])->name('room-type-descriptions.index');
        Route::get('room-type-descriptions/{id}', [RoomTypeDescriptionController::class, 'show'])->name('room-type-descriptions.show');
        Route::put('room-types/{id}/descriptions', [RoomTypeDescriptionController::class, 'update'])->name('room-type-descriptions.update');
        Route::post('room-type-descriptions', [RoomTypeDescriptionController::class, 'store'])->name('room-type-descriptions.store');
        Route::delete('room-type-descriptions', [RoomTypeDescriptionController::class, 'destroy'])->name('room-type-descriptions.destroy');

        Route::get('accommodation-descriptions', [AccommodationDescriptionController::class, 'index'])->name('accommodation-descriptions.index');
        Route::get('accommodation-descriptions/{id}', [AccommodationDescriptionController::class, 'show'])->name('accommodation-descriptions.show');
        Route::post('accommodation-descriptions', [AccommodationDescriptionController::class, 'store'])->name('accommodation-descriptions.store');
        Route::put('accommodation-descriptions/{id}', [AccommodationDescriptionController::class, 'update'])->name('accommodation-descriptions.update');
        Route::delete('accommodation-descriptions', [AccommodationDescriptionController::class, 'destroy'])->name('accommodation-descriptions.destroy');

        Route::get('accounts', [AccountController::class, 'index'])->name('accounts.index');
        Route::get('accounts/{id}', [AccountController::class, 'show'])->name('accounts.show');
        Route::post('accounts', [AccountController::class, 'store'])->name('accounts.store');
        Route::put('accounts/{id}', [AccountController::class, 'update'])->name('accounts.update');
        Route::delete('accounts', [AccountController::class, 'destroy'])->name('accounts.destroy');

        Route::get('account-types', [AccountTypeController::class, 'index'])->name('account-types.index');
        Route::get('plans', [PlanController::class, 'index'])->name('plans.index');

        Route::get('room-type-services', [RoomTypeServiceController::class, 'index'])->name('room-type-services.index');
        Route::get('room-type-services/{id}', [RoomTypeServiceController::class, 'show'])->name('room-type-services.show');
        Route::put('room-types/{id}/services', [RoomTypeServiceController::class, 'update'])->name('room-type-services.update');
        Route::post('room-type-services', [RoomTypeServiceController::class, 'store'])->name('room-type-services.store');
        Route::delete('room-type-services', [RoomTypeServiceController::class, 'destroy'])->name('room-type-services.destroy');

        Route::get('rate-plans', [RatePlanController::class, 'index'])->name('rate-plans.index');
        Route::get('rate-plans/{id}', [RatePlanController::class, 'show'])->name('rate-plans.show');
        Route::post('rate-plans', [RatePlanController::class, 'store'])->name('rate-plans.store');
        Route::put('rate-plans/{id}', [RatePlanController::class, 'update'])->name('rate-plans.update');
        Route::delete('rate-plans', [RatePlanController::class, 'destroy'])->name('rate-plans.destroy');

        Route::get('rates', [RateController::class, 'index'])->name('rates.index');
        Route::get('rates/{id}', [RateController::class, 'show'])->name('rates.show');
        Route::post('rates', [RateController::class, 'store'])->name('rates.store');
        Route::put('rates/{id}', [RateController::class, 'update'])->name('rates.update');
        Route::delete('rates', [RateController::class, 'destroy'])->name('rates.destroy');

        Route::get('room-availability', [RoomAvailabilityController::class, 'index'])->name('room-availability.index');
        Route::get('room-availability/{id}', [RoomAvailabilityController::class, 'show'])->name('room-availability.show');
        Route::post('room-availability', [RoomAvailabilityController::class, 'store'])->name('room-availability.store');
        Route::put('room-availability/{id}', [RoomAvailabilityController::class, 'update'])->name('room-availability.update');
        Route::delete('room-availability', [RoomAvailabilityController::class, 'destroy'])->name('room-availability.destroy');

        Route::get('room-type-beds', [RoomTypeBedController::class, 'index'])->name('room-type-beds.index');
        Route::get('room-type-beds/{id}', [RoomTypeBedController::class, 'show'])->name('room-type-beds.show');
        Route::post('room-type-beds', [RoomTypeBedController::class, 'store'])->name('room-type-beds.store');
        Route::put('room-type-beds/{id}', [RoomTypeBedController::class, 'update'])->name('room-type-beds.update');
        Route::delete('room-type-beds', [RoomTypeBedController::class, 'destroy'])->name('room-type-beds.destroy');

        Route::prefix('web')->group(function () {
            Route::get('accommodations', [WebAccommodationController::class, 'index'])->name('web.accommodations.index');
            Route::get('accommodations/{slug}', [WebAccommodationController::class, 'show'])->name('web.accommodations.show');
        });
    });
});
