<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\MercadoPagoNotification;
use App\Repositories\AccommodationBillingRepository;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Procesa un aviso de Mercado Pago ya guardado. Corre después de responder 200
 * (`dispatchAfterResponse`) o desde `mercadopago:retry-notifications`. Un error
 * no se propaga: queda en la fila (`last_error`) para el próximo reintento.
 */
class ProcessMercadoPagoNotification
{
    use Dispatchable;

    public function __construct(private int $notificationId) {}

    public function handle(AccommodationBillingRepository $billing): void
    {
        $notification = MercadoPagoNotification::find($this->notificationId);
        if ($notification === null || $notification->processed_at !== null) {
            return;
        }

        try {
            match ($notification->topic) {
                MercadoPagoNotification::PREAPPROVAL => $billing->syncPreapproval($notification->resource_id),
                MercadoPagoNotification::AUTHORIZED_PAYMENT => $billing->syncAuthorizedPayment((int) $notification->resource_id),
                default => null, // Otros tópicos (p. ej. `payment`): sólo quedan registrados.
            };
            $notification->update(['processed_at' => now(), 'last_error' => null, 'attempts' => $notification->attempts + 1]);
        } catch (Throwable $e) {
            $notification->update(['attempts' => $notification->attempts + 1, 'last_error' => mb_substr($e->getMessage(), 0, 1000)]);
            Log::error('Mercado Pago: falló el procesamiento del aviso', [
                'notification' => $notification->id,
                'topic' => $notification->topic,
                'resource_id' => $notification->resource_id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
