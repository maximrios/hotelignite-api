<?php

namespace App\Repositories\Contracts;

use App\Http\Resources\V1\InquiryResource;
use App\Models\Accommodation;
use App\Models\Inquiry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

interface InquiryInterface
{
    public function all(Request $request);

    public function store($request);

    /**
     * Alta desde un portal (`client/v1`), ya validada y con la tenencia
     * chequeada: `$inquirable` es un alojamiento o una experiencia visible.
     *
     * @param  array<string, mixed>  $data
     */
    public function storeFromPortal(Model $inquirable, int $clientId, array $data): InquiryResource;

    /**
     * Alta desde el asistente del alojamiento (`/mcp/accommodation`), ya
     * validada. Idempotente por teléfono + llegada durante unos minutos: si el
     * agente reintenta, devuelve la consulta existente.
     *
     * @param  array<string, mixed>  $data
     */
    public function storeFromAgent(Accommodation $accommodation, array $data): Inquiry;

    public function setStatus(Inquiry $inquiry, string $status): InquiryResource;
}
