<?php

namespace App\Http\Controllers\Api\ClientPanel\V1;

use App\Http\Resources\ClientPanel\SharedDocumentResource;
use App\Models\Accommodation;
use App\Models\Document;
use App\Repositories\Contracts\DocumentInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Lado client del legajo: ve SOLO los documentos que le compartieron de un
 * alojamiento que tenga visible (pivote `active`), más el estado de su checklist.
 * Nunca la bolsa completa. La descarga valida el share en cada request (§7).
 */
class DocumentController extends BaseController
{
    public function __construct(private DocumentInterface $documents)
    {
    }

    public function index(Request $request, Accommodation $accommodation): JsonResponse
    {
        $clientId = $this->assertVisible($request, $accommodation);

        $result = $this->documents->sharedWithClient($accommodation, $clientId);

        return response()->json([
            'data' => SharedDocumentResource::collection($result['documents']),
            'checklist' => $result['checklist'],
        ]);
    }

    public function requirements(Request $request): JsonResponse
    {
        $requirements = $this->documents->requirementsForClient($request->user()->client_id);

        return response()->json([
            'data' => $requirements->map(fn ($r) => [
                'document_type_id' => $r->document_type_id,
                'document_type' => $r->documentType?->name,
                'required' => $r->required,
                'notes' => $r->notes,
            ])->values(),
        ]);
    }

    public function download(Request $request, Accommodation $accommodation, Document $document): StreamedResponse
    {
        $clientId = $this->assertVisible($request, $accommodation);

        // El documento debe pertenecer a este alojamiento Y estar compartido con
        // este client. Sin share vigente → 403 (revocación real).
        abort_unless(
            $document->documentable_type === Accommodation::class
                && (int) $document->documentable_id === (int) $accommodation->id,
            404,
            'Documento no encontrado.'
        );
        abort_unless($document->isSharedWith($clientId), 403, 'No tenés acceso a este documento.');

        return Storage::disk($document->disk)->download($document->path, $document->original_name);
    }

    /**
     * El alojamiento tiene que ser visible para el client (pivote `active`).
     * Reutiliza `scopeVisibleTo`. Devuelve el client_id del token.
     */
    private function assertVisible(Request $request, Accommodation $accommodation): int
    {
        $clientId = (int) $request->user()->client_id;

        abort_unless(
            Accommodation::visibleTo($request->user())->whereKey($accommodation->id)->exists(),
            404,
            'Alojamiento no encontrado.'
        );

        return $clientId;
    }
}
