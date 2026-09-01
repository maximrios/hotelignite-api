<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // `SANCTUM_TOKEN_EXPIRATION` marca los tokens como vencidos pero no los
        // borra: `personal_access_tokens` crecía sin techo con credenciales
        // muertas. Se purgan con un día de gracia sobre el vencimiento, para no
        // pisar una sesión que el usuario todavía podría estar renovando.
        $schedule->command('sanctum:prune-expired --hours=24')->daily();

        // Los jobs fallidos quedan para siempre en `failed_jobs`. Una semana
        // alcanza para diagnosticar un envío de invitaciones que se cayó.
        $schedule->command('queue:prune-failed --hours=168')->daily();
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
