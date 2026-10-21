<?php

namespace App\Http\Controllers\Api\ClientPanel\V1;

use App\Http\Resources\V1\InquiryResource;
use App\Models\Inquiry;
use App\Repositories\Contracts\InquiryInterface;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Validation\Rule;

/**
 * Bandeja de consultas del client: las de sus experiencias y las de los
 * alojamientos de su padrón (`Inquiry::scopeVisibleTo`). Puede marcarlas como
 * respondidas; la respuesta en sí va por el canal del viajero (email/teléfono).
 */
class InquiryController extends BaseController
{
    public function __construct(private InquiryInterface $inquiries) {}

    public function index(Request $request)
    {
        $request->validate([
            'type' => ['sometimes', Rule::in(array_keys(Inquiry::TYPES))],
            'status' => ['sometimes', Rule::in(Inquiry::STATUSES)],
            'experience_id' => ['sometimes', 'integer'],
            'accommodation_id' => ['sometimes', 'integer'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return $this->inquiries->all($request);
    }

    public function update(Request $request, string $inquiry): InquiryResource
    {
        $data = $request->validate(['status' => ['required', Rule::in(Inquiry::STATUSES)]]);

        // Lo ajeno es 404: no se confirma que exista.
        $model = Inquiry::query()->visibleTo($request->user())->findOrFail($inquiry);

        return $this->inquiries->setStatus($model, $data['status']);
    }
}
