<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Models\DocumentType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

/**
 * Catálogo de tipos de documento. Referencia para armar el legajo y los
 * checklists — se expone a cualquier usuario del panel (no `platform`).
 */
class DocumentTypeController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        $types = DocumentType::query()
            ->where('is_active', true)
            ->when($request->filled('entity_type'), fn ($q) => $q->where(fn ($w) => $w
                ->where('entity_type', $request->query('entity_type'))
                ->orWhereNull('entity_type')))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'slug', 'name', 'entity_type', 'owner_level', 'sort_order']);

        return response()->json(['data' => $types]);
    }
}
