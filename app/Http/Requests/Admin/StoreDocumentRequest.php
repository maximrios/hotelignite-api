<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta de un documento al legajo. Recibe el archivo (no una URL): el legajo es
 * privado, el binario lo guarda la API en un disco privado. Ver §7.
 */
class StoreDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // la tenencia la valida el controller vía AccommodationPolicy
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'document_type_id' => ['nullable', 'integer', Rule::exists('document_types', 'id')],
            // 20 MB. Tipos habituales de legajo: PDF, imágenes, ofimática.
            'file' => ['required', 'file', 'max:20480', 'mimes:pdf,jpg,jpeg,png,webp,doc,docx,xls,xlsx'],
            'issued_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:issued_at'],
        ];
    }
}
