<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Jobs\ProcessMercadoPagoNotification;
use App\Models\MercadoPagoNotification;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Receptor de webhooks de Mercado Pago (público, sin auth). Valida la firma
 * `x-signature` (HMAC-SHA256 con MP_WEBHOOK_SECRET), guarda el aviso, responde
 * 200 y lo procesa después de responder. Nunca confía en el body: el
 * procesamiento relee el recurso en Mercado Pago.
 */
class MercadoPagoWebhookController extends BaseController
{
    public function handle(Request $request)
    {
        $secret = (string) config('services.mercadopago.webhook_secret');
        if ($secret === '') {
            Log::error('Mercado Pago: llegó un webhook pero falta MP_WEBHOOK_SECRET');

            return response()->json(['error' => 'not configured'], 503);
        }

        // PHP convierte el punto de `data.id` del query string en `_`.
        $dataId = strtolower((string) ($request->query('data_id') ?? $request->input('data.id', '')));
        $requestId = (string) $request->header('x-request-id', '');

        if (! $this->validSignature((string) $request->header('x-signature', ''), $dataId, $requestId, $secret)) {
            return response()->json(['error' => 'invalid signature'], 401);
        }

        $topic = (string) ($request->input('type') ?? $request->query('type') ?? $request->query('topic', ''));
        if ($topic === '' || $dataId === '') {
            return response()->json(['ok' => true]); // Firmado pero sin recurso: nada que hacer.
        }

        $notification = DB::transaction(function () use ($topic, $dataId, $requestId, $request) {
            $existing = MercadoPagoNotification::where('topic', $topic)->where('resource_id', $dataId)->lockForUpdate()->first();
            if ($existing !== null) {
                // Un aviso nuevo del mismo recurso = cambió algo: se vuelve a procesar.
                $existing->update([
                    'request_id' => $requestId ?: null,
                    'payload' => $request->json()->all(),
                    'received_count' => $existing->received_count + 1,
                    'attempts' => 0,
                    'processed_at' => null,
                ]);

                return $existing;
            }

            return MercadoPagoNotification::create([
                'topic' => $topic,
                'resource_id' => $dataId,
                'request_id' => $requestId ?: null,
                'payload' => $request->json()->all(),
            ]);
        });

        ProcessMercadoPagoNotification::dispatchAfterResponse($notification->id);

        return response()->json(['ok' => true]);
    }

    /**
     * Manifest `id:{data.id};request-id:{x-request-id};ts:{ts};`, omitiendo los
     * que no vinieron (documentación oficial de Mercado Pago).
     */
    private function validSignature(string $header, string $dataId, string $requestId, string $secret): bool
    {
        $ts = null;
        $hash = null;
        foreach (explode(',', $header) as $part) {
            $kv = explode('=', $part, 2);
            if (count($kv) !== 2) {
                continue;
            }
            [$key, $value] = [trim($kv[0]), trim($kv[1])];
            if ($key === 'ts') {
                $ts = $value;
            } elseif ($key === 'v1') {
                $hash = $value;
            }
        }
        if ($ts === null || $hash === null) {
            return false;
        }

        $parts = [];
        if ($dataId !== '') {
            $parts[] = "id:{$dataId}";
        }
        if ($requestId !== '') {
            $parts[] = "request-id:{$requestId}";
        }
        $parts[] = "ts:{$ts}";

        return hash_equals(hash_hmac('sha256', implode(';', $parts).';', $secret), $hash);
    }
}
