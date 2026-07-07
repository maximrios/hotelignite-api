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
use App\Repositories\RoomTypeRepository;
use App\Repositories\Contracts\TourInterface;
use App\Repositories\TravelAgencyRespository;
use App\Repositories\AccommodationRespository;
use App\Repositories\AccommodationTypeRepository;
use App\Repositories\CityRepository;
use App\Repositories\Contracts\BookingInterface;
use App\Repositories\Contracts\ChannelInterface;
use App\Repositories\Contracts\ReservationInterface;
use App\Repositories\Contracts\RoomTypeInterface;
use App\Repositories\Contracts\TravelAgencyInterface;
use App\Repositories\Contracts\AccommodationInterface;
use App\Repositories\Contracts\AccommodationTypeInterface;
use App\Repositories\Contracts\CityInterface;
use App\Repositories\Contracts\ServiceInterface;
use App\Repositories\ServiceRepository;
use App\Repositories\Contracts\AccommodationServiceInterface;
use App\Repositories\AccommodationServiceRepository;
use App\Repositories\Contracts\AccommodationPolicyOldInterface;
use App\Repositories\AccommodationPolicyOldRepository;
use App\Repositories\Contracts\AccommodationPolicyInterface;
use App\Repositories\AccommodationPolicyRepository;
use App\Repositories\Contracts\AccommodationPolicyTranslationInterface;
use App\Repositories\AccommodationPolicyTranslationRepository;
use App\Repositories\Contracts\AccommodationRatePolicyInterface;
use App\Repositories\AccommodationRatePolicyRepository;
use App\Repositories\Contracts\PolicyInterface;
use App\Repositories\PolicyRepository;
use App\Repositories\Contracts\RoomTypeDescriptionInterface;
use App\Repositories\RoomTypeDescriptionRepository;
use App\Repositories\Contracts\RoomTypeServiceInterface;
use App\Repositories\RoomTypeServiceRepository;
use App\Repositories\Contracts\RoomInterface;
use App\Repositories\RoomRepository;
use App\Repositories\Contracts\InquiryInterface;
use App\Repositories\InquiryRepository;
use App\Repositories\Contracts\RatePlanInterface;
use App\Repositories\RatePlanRepository;
use App\Repositories\Contracts\RateInterface;
use App\Repositories\RateRepository;
use App\Repositories\Contracts\RoomAvailabilityInterface;
use App\Repositories\RoomAvailabilityRepository;
use App\Repositories\Contracts\RoomTypeBedInterface;
use App\Repositories\RoomTypeBedRepository;
use App\Repositories\Contracts\AccommodationDescriptionInterface;
use App\Repositories\AccommodationDescriptionRepository;
use App\Repositories\Contracts\AccountInterface;
use App\Repositories\AccountRepository;

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
        $this->app->bind(PolicyInterface::class, PolicyRepository::class);
        $this->app->bind(RoomTypeInterface::class, RoomTypeRepository::class);
        $this->app->bind(AccommodationServiceInterface::class, AccommodationServiceRepository::class);
        $this->app->bind(AccommodationPolicyOldInterface::class, AccommodationPolicyOldRepository::class);
        $this->app->bind(AccommodationPolicyInterface::class, AccommodationPolicyRepository::class);
        $this->app->bind(AccommodationPolicyTranslationInterface::class, AccommodationPolicyTranslationRepository::class);
        $this->app->bind(AccommodationRatePolicyInterface::class, AccommodationRatePolicyRepository::class);
        $this->app->bind(RoomTypeDescriptionInterface::class, RoomTypeDescriptionRepository::class);
        $this->app->bind(RoomTypeServiceInterface::class, RoomTypeServiceRepository::class);
        $this->app->bind(RoomInterface::class, RoomRepository::class);
        $this->app->bind(InquiryInterface::class, InquiryRepository::class);
        $this->app->bind(RatePlanInterface::class, RatePlanRepository::class);
        $this->app->bind(RateInterface::class, RateRepository::class);
        $this->app->bind(RoomAvailabilityInterface::class, RoomAvailabilityRepository::class);
        $this->app->bind(RoomTypeBedInterface::class, RoomTypeBedRepository::class);
        $this->app->bind(AccommodationDescriptionInterface::class, AccommodationDescriptionRepository::class);
        $this->app->bind(AccountInterface::class, AccountRepository::class);
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
