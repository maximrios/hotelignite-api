<?php

namespace App\Console\Commands;

use App\Models\BookingHold;
use Illuminate\Console\Command;

class PruneBookingHolds extends Command
{
    protected $signature = 'booking:prune-holds';

    protected $description = 'Borra los holds del motor vencidos sin reserva';

    public function handle(): int
    {
        $deleted = BookingHold::whereNull('submitted_at')
            ->where('expires_at', '<', now()->subDay())
            ->delete();

        $this->info("Holds borrados: {$deleted}");

        return self::SUCCESS;
    }
}
