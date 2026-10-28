<?php

declare(strict_types=1);

namespace App\Services;

use MercadoPago\Client\Invoice\InvoiceClient;
use MercadoPago\Client\PreApproval\PreApprovalClient;
use MercadoPago\Exceptions\MPApiException;
use MercadoPago\MercadoPagoConfig;
use MercadoPago\Resources\Invoice;
use MercadoPago\Resources\PreApproval;
use RuntimeException;

/**
 * Suscripciones de Mercado Pago (`https://api.mercadopago.com/preapproval`, sin
 * prefijo /v1) con el SDK oficial. Sólo transporte: monto, frecuencia y a quién
 * se le cobra los decide `AccommodationBillingRepository`, nunca el navegador.
 *
 * Errores de Mercado Pago → `RuntimeException` con el status y el mensaje de la
 * API para el log; al hotelero se le muestra un texto propio.
 */
class MercadoPagoSubscriptions
{
    /** Estados de un preapproval en Mercado Pago. */
    public const PENDING = 'pending';

    public const AUTHORIZED = 'authorized';

    public const PAUSED = 'paused';

    public const CANCELED = 'canceled';

    /** Acciones de ciclo de vida permitidas → estado que se manda a Mercado Pago. */
    private const LIFECYCLE = [
        'pause' => self::PAUSED,
        'reactivate' => self::AUTHORIZED,
        'cancel' => self::CANCELED,
    ];

    /** Crea la suscripción en `pending`: el hotelero la autoriza en `init_point`. */
    public function createPending(array $body): PreApproval
    {
        return $this->call(fn () => (new PreApprovalClient)->create([...$body, 'status' => self::PENDING]));
    }

    public function find(string $id): PreApproval
    {
        return $this->call(fn () => (new PreApprovalClient)->get($id));
    }

    /** Cobro recurrente de una suscripción (`/authorized_payments/{id}`; el SDK lo llama Invoice). */
    public function findAuthorizedPayment(int $id): Invoice
    {
        return $this->call(fn () => (new InvoiceClient)->get($id));
    }

    /** `pause` | `reactivate` | `cancel`; cualquier otra acción es un error de programación. */
    public function transition(string $id, string $action): PreApproval
    {
        $status = self::LIFECYCLE[$action] ?? throw new \InvalidArgumentException("Acción de suscripción inválida: {$action}");

        return $this->call(fn () => (new PreApprovalClient)->update($id, ['status' => $status]));
    }

    /**
     * @template T of PreApproval|Invoice
     *
     * @param  callable(): T  $request
     * @return T
     */
    private function call(callable $request): PreApproval|Invoice
    {
        // Se configura al usarse (no al construirse): sin token, el resto de la
        // facturación sigue andando y sólo falla el cobro.
        $token = (string) config('services.mercadopago.access_token');
        if ($token === '') {
            throw new RuntimeException('Falta MP_ACCESS_TOKEN: Mercado Pago no está configurado.');
        }
        MercadoPagoConfig::setAccessToken($token);

        try {
            return $request();
        } catch (MPApiException $e) {
            $content = $e->getApiResponse()->getContent();
            throw new RuntimeException(sprintf(
                'Mercado Pago %d: %s',
                $e->getStatusCode(),
                $content['message'] ?? $e->getMessage(),
            ), $e->getStatusCode(), $e);
        }
    }
}
