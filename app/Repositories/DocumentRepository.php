<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Accommodation;
use App\Models\ClientDocumentRequirement;
use App\Models\Document;
use App\Models\DocumentShare;
use App\Models\User;
use App\Repositories\Contracts\DocumentInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Legajo de prestadores: bolsa privada + shares con consentimiento. Ver
 * `docs/documents-plan.md`. La visibilidad de un client se define exclusivamente
 * por la existencia de un `DocumentShare` — nunca por la bolsa.
 */
class DocumentRepository implements DocumentInterface
{
    public function bag(Accommodation $accommodation)
    {
        return $accommodation->documents()
            ->with(['documentType', 'shares.client', 'uploadedBy'])
            ->latest()
            ->get();
    }

    public function store(Accommodation $accommodation, array $data, UploadedFile $file, User $user): Document
    {
        $path = $file->store("accommodation/{$accommodation->id}", 'documents');

        return $accommodation->documents()->create([
            'document_type_id' => $data['document_type_id'] ?? null,
            'title' => $data['title'],
            'disk' => 'documents',
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'uploaded_by_user_id' => $user->id,
            'issued_at' => $data['issued_at'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
        ]);
    }

    public function destroy(Document $document): void
    {
        DB::transaction(function () use ($document) {
            // Sacamos el binario y los shares: des-compartir tiene que ser real e
            // inmediato (§7). Después soft-delete de la fila (histórico).
            Storage::disk($document->disk)->delete($document->path);
            $document->shares()->delete();
            $document->delete();
        });
    }

    public function share(Document $document, int $clientId, User $user): void
    {
        // Solo se comparte con un client vinculado al alojamiento (cualquier
        // estado del pivote: el onboarding comparte antes de que el staff apruebe).
        $accommodation = $document->documentable;
        abort_unless(
            $accommodation instanceof Accommodation
                && $accommodation->clients()->where('clients.id', $clientId)->exists(),
            422,
            'El client no está vinculado a este alojamiento.'
        );

        DocumentShare::firstOrCreate(
            ['document_id' => $document->id, 'client_id' => $clientId],
            ['shared_by_user_id' => $user->id, 'shared_at' => now()],
        );
    }

    public function unshare(Document $document, int $clientId): void
    {
        $document->shares()->where('client_id', $clientId)->delete();
    }

    public function sharingMatrix(Accommodation $accommodation): array
    {
        $documents = $accommodation->documents()->with('shares')->get();
        $clients = $accommodation->clients()->get();

        $rows = $clients->map(function ($client) use ($documents) {
            $requirements = $this->requirementsForClient($client->id);
            $sharedDocIds = $documents->filter(
                fn (Document $d) => $d->shares->contains('client_id', $client->id)
            )->pluck('id');

            $checklist = $requirements->map(function (ClientDocumentRequirement $req) use ($documents, $client) {
                // Un requisito está cumplido si hay un doc de ese tipo compartido con el client.
                $fulfilled = $documents->contains(fn (Document $d) => $d->document_type_id === $req->document_type_id
                    && $d->shares->contains('client_id', $client->id));

                return [
                    'document_type_id' => $req->document_type_id,
                    'required' => $req->required,
                    'status' => $fulfilled ? 'compartido' : 'faltante',
                ];
            })->values();

            return [
                'client_id' => $client->id,
                'client_name' => $client->name,
                'pivot_status' => $client->pivot->status,
                'shared_document_ids' => $sharedDocIds->values(),
                'checklist' => $checklist,
            ];
        })->values();

        return ['clients' => $rows];
    }

    public function sharedWithClient(Accommodation $accommodation, int $clientId): array
    {
        // Partimos de los SHARES del client, nunca de la bolsa: es imposible
        // enumerar el legajo desde acá.
        $shared = $accommodation->documents()
            ->whereHas('shares', fn ($q) => $q->where('client_id', $clientId))
            ->with('documentType')
            ->get();

        $requirements = $this->requirementsForClient($clientId);
        $sharedTypeIds = $shared->pluck('document_type_id')->filter()->unique();

        $checklist = $requirements->map(fn (ClientDocumentRequirement $req) => [
            'document_type_id' => $req->document_type_id,
            'document_type' => $req->documentType?->name,
            'required' => $req->required,
            'status' => $sharedTypeIds->contains($req->document_type_id) ? 'cumplido' : 'faltante',
        ])->values();

        return [
            'documents' => $shared,
            'checklist' => $checklist,
        ];
    }

    public function requirementsForClient(int $clientId, string $entityType = 'accommodation')
    {
        return ClientDocumentRequirement::with('documentType')
            ->where('client_id', $clientId)
            ->where('entity_type', $entityType)
            ->get();
    }
}
