<?php

namespace App\Console\Commands;

use App\Repositories\ReservationRepository;
use Illuminate\Console\Command;

class ExpireBookingRequests extends Command
{
    protected $signature = 'booking:expire-requests';

    protected $description = 'Cancela las solicitudes del motor que vencieron sin respuesta del hotel';

    public function handle(ReservationRepository $reservations): int
    {
        $this->info('Solicitudes vencidas: '.$reservations->expireRequests());

        return self::SUCCESS;
    }
}
