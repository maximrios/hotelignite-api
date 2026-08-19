<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\Accommodation;
use App\Models\Document;
use App\Models\User;
use Illuminate\Http\UploadedFile;

interface DocumentInterface
{
    /**
     * La bolsa completa de un alojamiento (vista del dueño).
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Document>
     */
    public function bag(Accommodation $accommodation);

    /**
     * Sube un archivo al legajo (disco privado) y crea el Document.
     *
     * @param  array<string, mixed>  $data
     */
    public function store(Accommodation $accommodation, array $data, UploadedFile $file, User $user): Document;

    /**
     * Borra el archivo del disco, sus shares, y hace soft-delete del Document.
     */
    public function destroy(Document $document): void;

    /**
     * Comparte un documento con un client (crea el consentimiento). Idempotente.
     */
    public function share(Document $document, int $clientId, User $user): void;

    /**
     * Revoca el share (des-comparte).
     */
    public function unshare(Document $document, int $clientId): void;

    /**
     * Matriz de sharing para el dueño: por client vinculado, su checklist con el
     * estado (compartido/faltante) de cada requisito.
     *
     * @return array<string, mixed>
     */
    public function sharingMatrix(Accommodation $accommodation): array;

    /**
     * Lado client: los documentos que le compartieron de este alojamiento, más el
     * estado de su checklist (cumplido/faltante). Nunca la bolsa completa.
     *
     * @return array<string, mixed>
     */
    public function sharedWithClient(Accommodation $accommodation, int $clientId): array;

    /**
     * El checklist de un client (qué exige).
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\ClientDocumentRequirement>
     */
    public function requirementsForClient(int $clientId, string $entityType = 'accommodation');
}
