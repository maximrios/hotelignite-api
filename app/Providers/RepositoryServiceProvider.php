<?php

namespace App\Providers;

use App\Models\Accommodation;
use App\Models\AccommodationType;
use App\Models\Service;
use App\Repositories\TourRepository;
use App\Repositories\BookingRepository;
use App\Repositories\ChannelRepository;
use Illuminate\Support\ServiceProvider;
use App\Repositories\ReservationRepository;
use App\Repositories\Contracts\TourInterface;
use App\Repositories\TravelAgencyRespository;
use App\Repositories\AccommodationRespository;
use App\Repositories\AccommodationTypeRepository;
use App\Repositories\CityRepository;
use App\Repositories\Contracts\BookingInterface;
use App\Repositories\Contracts\ChannelInterface;
use App\Repositories\Contracts\ReservationInterface;
use App\Repositories\Contracts\TravelAgencyInterface;
use App\Repositories\Contracts\AccommodationInterface;
use App\Repositories\Contracts\AccommodationTypeInterface;
use App\Repositories\Contracts\CityInterface;
use App\Repositories\Contracts\ServiceInterface;
use App\Repositories\ServiceRepository;

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
        $this->app->bind(AccommodationTypeInterface::class, AccommodationTypeRepository::class);
        $this->app->bind(TravelAgencyInterface::class, TravelAgencyRespository::class);
        $this->app->bind(TourInterface::class, TourRepository::class);
        $this->app->bind(BookingInterface::class, BookingRepository::class);
        $this->app->bind(ChannelInterface::class, ChannelRepository::class);
        $this->app->bind(CityInterface::class, CityRepository::class);
        $this->app->bind(ReservationInterface::class, ReservationRepository::class);
        $this->app->bind(ServiceInterface::class, ServiceRepository::class);
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
