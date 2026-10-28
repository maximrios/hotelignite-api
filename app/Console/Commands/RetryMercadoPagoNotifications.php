<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ProcessMercadoPagoNotification;
use App\Models\MercadoPagoNotification;
use Illuminate\Console\Command;

/**
 * Reprocesa los avisos de Mercado Pago que fallaron (o que se cortaron antes de
 * procesarse). Agendado cada 5 minutos en `routes/console.php`.
 */
class RetryMercadoPagoNotifications extends Command
{
    protected $signature = 'mercadopago:retry-notifications';

    protected $description = 'Reprocesa los webhooks de Mercado Pago pendientes o fallidos';

    public function handle(): int
    {
        $pending = MercadoPagoNotification::whereNull('processed_at')
            ->where('attempts', '<', MercadoPagoNotification::MAX_ATTEMPTS)
            // Los recién llegados los está procesando el propio webhook.
            ->where('updated_at', '<', now()->subMinutes(2))
            ->orderBy('id')
            ->limit(100)
            ->pluck('id');

        foreach ($pending as $id) {
            ProcessMercadoPagoNotification::dispatchSync($id);
        }

        $this->info("Reprocesados: {$pending->count()}");

        return self::SUCCESS;
    }
}
