<?php

namespace App\Providers;

use App\Repositories\TourRepository;
use App\Repositories\BookingRepository;
use Illuminate\Support\ServiceProvider;
use App\Repositories\Contracts\TourInterface;
use App\Repositories\TravelAgencyRespository;
use App\Repositories\AccommodationRespository;
use App\Repositories\Contracts\BookingInterface;
use App\Repositories\Contracts\TravelAgencyInterface;
use App\Repositories\Contracts\AccommodationInterface;
use App\Repositories\Contracts\ReservationInterface;
use App\Repositories\ReservationRepository;

class RepositoryServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->bind(AccommodationInterface::class, AccommodationRespository::class);
        $this->app->bind(TravelAgencyInterface::class, TravelAgencyRespository::class);
        $this->app->bind(TourInterface::class, TourRepository::class);
        $this->app->bind(BookingInterface::class, BookingRepository::class);
        $this->app->bind(ReservationInterface::class, ReservationRepository::class);
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }
}
