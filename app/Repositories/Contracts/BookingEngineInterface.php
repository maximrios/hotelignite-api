<?php

namespace App\Repositories\Contracts;

use App\Models\Accommodation;

interface BookingEngineInterface
{
    /** @return array<string, mixed> */
    public function hotel(Accommodation $accommodation): array;

    /**
     * @param  array<string, mixed>  $stay
     * @return array<string, mixed>
     */
    public function search(Accommodation $accommodation, array $stay): array;

    /**
     * @param  array<string, mixed>  $stay
     * @return array{token: string, expires_at: string}
     */
    public function hold(Accommodation $accommodation, array $stay, string $clientIp): array;

    /** @return array<string, mixed> */
    public function showHold(Accommodation $accommodation, string $token): array;

    /** Libera lo apartado. Una solicitud ya enviada no se toca. */
    public function releaseHold(Accommodation $accommodation, string $token): void;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function confirm(Accommodation $accommodation, string $token, array $data): array;
}
