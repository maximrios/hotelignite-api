<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\DocumentType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ClientDocumentRequirementSeeder extends Seeder
{
    /**
     * Checklist default para los clients existentes (Regla A, §5): el núcleo
     * regulatorio que un municipio/cámara suele exigir a un alojamiento. En V1 no
     * hay UI de edición — esto es el punto de partida, editable a mano después.
     * Idempotente: updateOrInsert por (client_id, entity_type, document_type_id).
     */
    public function run(): void
    {
        $defaultSlugs = ['habilitacion_municipal', 'certificado_bomberos', 'seguro_rc'];

        $typeIds = DocumentType::whereIn('slug', $defaultSlugs)->pluck('id', 'slug');

        if ($typeIds->isEmpty()) {
            return; // corré DocumentTypeSeeder primero
        }

        Client::all()->each(function (Client $client) use ($typeIds) {
            foreach ($typeIds as $documentTypeId) {
                DB::table('client_document_requirements')->updateOrInsert(
                    [
                        'client_id' => $client->id,
                        'entity_type' => 'accommodation',
                        'document_type_id' => $documentTypeId,
                    ],
                    ['required' => true, 'updated_at' => now(), 'created_at' => now()],
                );
            }
        });
    }
}
