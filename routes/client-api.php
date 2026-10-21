<?php

use App\Http\Controllers\Api\Client\AccommodationController;
use App\Http\Controllers\Api\Client\BookingController;
use App\Http\Controllers\Api\Client\CatalogController;
use App\Http\Controllers\Api\Client\ExperienceController;
use App\Http\Controllers\Api\Client\InquiryController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Client API (B2B) — /api/client/v1
|--------------------------------------------------------------------------
|
| Superficie para consumidores externos (portales turísticos, agencias,
| gobiernos) autenticados con API key de client. Whitelist estricta: sólo
| lectura de catálogo + creación de pre-reservas. NADA de escritura de catálogo.
| Autenticación: `auth.client` (API key). Rate limit: `throttle:client` (por tier).
| Tenencia: los accommodations se scopean al pivote del client dentro de cada
| controlador (`Accommodation::scopeVisibleTo`). Ver docs/api-clients-plan.md.
|
*/

Route::middleware(['json.response', 'auth.client', 'throttle:client'])->group(function () {

    // Catálogo de accommodations (scopeado al client)
    Route::get('accommodations', [AccommodationController::class, 'index'])->name('client.accommodations.index');
    Route::get('accommodations/{slug}', [AccommodationController::class, 'show'])->name('client.accommodations.show');
    Route::get('accommodations/{id}/availability', [AccommodationController::class, 'availability'])->name('client.accommodations.availability');
    Route::get('accommodations/{slug}/nearby-points', [AccommodationController::class, 'nearbyPoints'])->name('client.accommodations.nearby-points');

    // Experiencias del portal: sólo las del client de la key y publicadas
    // (docs/experiences-plan.md).
    Route::get('experiences', [ExperienceController::class, 'index'])->name('client.experiences.index');
    Route::get('experiences/{slug}', [ExperienceController::class, 'show'])->name('client.experiences.show');

    // Datos de referencia (catálogos compartidos)
    Route::get('cities', [CatalogController::class, 'cities'])->name('client.cities.index');
    Route::get('cities/{slug}', [CatalogController::class, 'city'])->name('client.cities.show');
    // Legacy: lo reemplazan las experiencias. Se borra cuando turinorte deje de usarlo.
    Route::get('tours', [CatalogController::class, 'tours'])->name('client.tours.index');
    Route::get('services', [CatalogController::class, 'services'])->name('client.services.index');
    Route::get('accommodation-types', [CatalogController::class, 'accommodationTypes'])->name('client.accommodation-types.index');

    // Consulta de un viajero sobre un alojamiento o una experiencia del portal
    // (requiere ability inquiry:create). Ver docs/experiences-plan.md.
    Route::post('inquiries', [InquiryController::class, 'store'])
        ->middleware('client.ability:inquiry:create')
        ->name('client.inquiries.store');

    // Pre-reserva (requiere ability booking:create)
    Route::post('bookings', [BookingController::class, 'store'])
        ->middleware('client.ability:booking:create')
        ->name('client.bookings.store');
});
