<?php

namespace App\Providers;

// use Illuminate\Support\Facades\Gate;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Laravel\Passport\Passport;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        \App\Models\Accommodation::class => \App\Policies\AccommodationPolicy::class,
        \App\Models\Account::class => \App\Policies\AccountPolicy::class,
        \App\Models\User::class => \App\Policies\UserPolicy::class,

        // Recursos hijos: heredan la tenencia del alojamiento dueño (B8).
        \App\Models\Booking::class => \App\Policies\ChildOfAccommodationPolicy::class,
        \App\Models\Reservation::class => \App\Policies\ChildOfAccommodationPolicy::class,
        \App\Models\Room::class => \App\Policies\ChildOfAccommodationPolicy::class,
        \App\Models\RoomType::class => \App\Policies\ChildOfAccommodationPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     *
     * @return void
     */
    public function boot()
    {
        $this->registerPolicies();

        //
    }
}
