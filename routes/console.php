<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| This file is where you may define all of your Closure based console
| commands. Each Closure is bound to a command instance allowing a
| simple approach to interacting with each command's IO methods.
|
*/

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Webhooks de Mercado Pago que fallaron. `schedule:run` lo corre el [program:scheduler]
// de docker/supervisord.conf dentro del contenedor (no hay cron en el host).
Schedule::command('mercadopago:retry-notifications')->everyFiveMinutes()->withoutOverlapping();

// Recordatorio de las invitaciones de acceso sin activar. A las 10 de la mañana
// del destino: que llegue en horario de oficina, no de madrugada.
Schedule::command('users:remind-invitations')
    ->dailyAt('10:00')
    ->timezone(config('app.destination_timezone', 'America/Argentina/Salta'))
    ->withoutOverlapping();
