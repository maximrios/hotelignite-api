<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Requests\Admin\ShareDocumentRequest;
use App\Http\Requests\Admin\StoreDocumentRequest;
use App\Http\Resources\Admin\DocumentResource;
use App\Models\Accommodation;
use App\Models\Document;
use App\Repositories\Contracts\DocumentInterface;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller as BaseController;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Legajo del alojamiento, del lado del dueño (account/platform). Subir, borrar,
 * compartir/des-compartir y ver la matriz de sharing. Tenencia vía
 * AccommodationPolicy. Los clients (solo lectura) no llegan acá: el grupo está
 * bajo `client.readonly`. Ver `docs/documents-plan.md` §8.
 */
class AccommodationDocumentController extends BaseController
{
    use AuthorizesRequests;

    public function __construct(private DocumentInterface $documents)
    {
    }

    public function index(Accommodation $accommodation): JsonResponse
    {
        $this->authorize('view', $accommodation);

        return response()->json([
            'data' => DocumentResource::collection($this->documents->bag($accommodation)),
        ]);
    }

    public function store(StoreDocumentRequest $request, Accommodation $accommodation): JsonResponse
    {
        $this->authorize('update', $accommodation);

        $document = $this->documents->store(
            $accommodation,
            $request->validated(),
            $request->file('file'),
            $request->user(),
        );

        return (new DocumentResource($document->load('documentType')))->response()->setStatusCode(201);
    }

    public function destroy(Accommodation $accommodation, Document $document): JsonResponse
    {
        $this->authorize('update', $accommodation);
        $this->assertOwned($accommodation, $document);

        $this->documents->destroy($document);

        return response()->json(['message' => 'Documento eliminado.']);
    }

    public function download(Accommodation $accommodation, Document $document): StreamedResponse
    {
        $this->authorize('view', $accommodation);
        $this->assertOwned($accommodation, $document);

        return \Illuminate\Support\Facades\Storage::disk($document->disk)
            ->download($document->path, $document->original_name);
    }

    public function sharing(Accommodation $accommodation): JsonResponse
    {
        $this->authorize('view', $accommodation);

        return response()->json($this->documents->sharingMatrix($accommodation));
    }

    public function share(ShareDocumentRequest $request, Accommodation $accommodation, Document $document): JsonResponse
    {
        $this->authorize('update', $accommodation);
        $this->assertOwned($accommodation, $document);

        $this->documents->share($document, (int) $request->validated()['client_id'], $request->user());

        return response()->json(['message' => 'Documento compartido.']);
    }

    public function unshare(Accommodation $accommodation, Document $document, int $clientId): JsonResponse
    {
        $this->authorize('update', $accommodation);
        $this->assertOwned($accommodation, $document);

        $this->documents->unshare($document, $clientId);

        return response()->json(['message' => 'Documento des-compartido.']);
    }

    /**
     * Un documento solo se opera desde su alojamiento dueño.
     */
    private function assertOwned(Accommodation $accommodation, Document $document): void
    {
        abort_unless(
            $document->documentable_type === Accommodation::class
                && (int) $document->documentable_id === (int) $accommodation->id,
            404,
            'Documento no encontrado.'
        );
    }
}
