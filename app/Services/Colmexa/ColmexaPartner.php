<?php

namespace App\Services\Colmexa;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente de la API de partners de Colmexa (`/api/partner/v1`, contrato en
 * colmexa/backend/docs/api-partner.md). HotelIgnite es el partner; cada
 * alojamiento es un tenant, identificado por su id como `ref`.
 *
 * Todos los PUT son idempotentes: se manda el estado deseado completo.
 */
class ColmexaPartner
{
    public function isConfigured(): bool
    {
        return filled(config('services.colmexa.url')) && filled(config('services.colmexa.partner_token'));
    }

    /**
     * @param  array{name: string, timezone?: string, status?: string}  $data
     * @return array<string, mixed>
     */
    public function upsertTenant(int $accommodationId, array $data): array
    {
        return $this->send('put', "tenants/{$accommodationId}", $data);
    }

    /**
     * El usuario principal del tenant, como owner. Idempotente: si ya es
     * miembro no se toca.
     *
     * @return array<string, mixed>
     */
    public function upsertOwner(int $accommodationId, string $email, ?string $name): array
    {
        return $this->send('put', "tenants/{$accommodationId}/owner", array_filter([
            'email' => $email,
            'name' => $name,
        ], fn (mixed $v): bool => $v !== null && $v !== ''));
    }

    /**
     * Enlace de un solo uso (2 minutos) que deja a esta persona adentro de la
     * bandeja del alojamiento. Null si no es del equipo de esa bandeja (404 de
     * Colmexa): el equipo lo maneja el hotel desde ahí, no HotelIgnite.
     *
     * @throws ColmexaException
     */
    public function loginLink(int $accommodationId, string $email, ?string $redirectTo = null): ?string
    {
        $data = $this->send('post', "tenants/{$accommodationId}/login-links", array_filter([
            'email' => $email,
            'redirect_to' => $redirectTo,
        ], fn (mixed $v): bool => $v !== null), allowNotFound: true);

        $url = $data['url'] ?? null;

        return is_string($url) ? $url : null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function provisionAgent(int $accommodationId, string $agent, array $data): array
    {
        return $this->send('put', "tenants/{$accommodationId}/agents/{$agent}", $data);
    }

    public function deactivateAgent(int $accommodationId, string $agent): void
    {
        $this->send('delete', "tenants/{$accommodationId}/agents/{$agent}", null, allowNotFound: true);
    }

    /**
     * @return array<string, mixed>
     */
    public function grantCredits(int $accommodationId, int $amount, string $reference, ?string $note = null): array
    {
        return $this->send('post', "tenants/{$accommodationId}/credits", array_filter([
            'amount' => $amount,
            'reference' => $reference,
            'note' => $note,
        ], fn (mixed $v): bool => $v !== null));
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     */
    protected function send(string $method, string $path, ?array $body, bool $allowNotFound = false): array
    {
        if (! $this->isConfigured()) {
            throw new ColmexaException('Los agentes IA todavía no están habilitados en este servidor.');
        }

        try {
            /** @var Response $response */
            $response = $this->client()->{$method}($path, $body ?? []);
        } catch (ConnectionException $e) {
            Log::warning('colmexa: sin conexión', ['path' => $path, 'error' => $e->getMessage()]);

            throw new ColmexaException('No pudimos comunicarnos con el servicio de agentes. Probá de nuevo en un momento.', previous: $e);
        }

        if ($allowNotFound && $response->status() === 404) {
            return [];
        }

        if ($response->failed()) {
            Log::warning('colmexa: respuesta con error', [
                'path' => $path,
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 1000),
            ]);

            throw new ColmexaException(match (true) {
                $response->status() === 422 => 'El servicio de agentes rechazó los datos: '.$this->firstError($response),
                $response->status() === 401, $response->status() === 403 => 'El servicio de agentes rechazó la credencial de HotelIgnite.',
                default => 'El servicio de agentes respondió con un error. Probá de nuevo en un momento.',
            });
        }

        $data = $response->json('data');

        return is_array($data) ? $data : [];
    }

    protected function client(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.colmexa.url'), '/').'/api/partner/v1')
            ->withToken((string) config('services.colmexa.partner_token'))
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('services.colmexa.timeout', 20));
    }

    protected function firstError(Response $response): string
    {
        $errors = $response->json('errors');

        if (is_array($errors)) {
            foreach ($errors as $messages) {
                if (is_array($messages) && is_string($messages[0] ?? null)) {
                    return $messages[0];
                }
            }
        }

        $message = $response->json('message');

        return is_string($message) ? $message : 'datos inválidos.';
    }
}
