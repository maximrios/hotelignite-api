<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un ítem del checklist de un client: "exijo este tipo de documento". La
 * exigencia es del client; el archivo es del prestador (Regla A). Ver §5.
 */
class ClientDocumentRequirement extends Model
{
    protected $fillable = [
        'client_id',
        'entity_type',
        'document_type_id',
        'required',
        'notes',
    ];

    protected $casts = [
        'required' => 'boolean',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function documentType()
    {
        return $this->belongsTo(DocumentType::class);
    }
}
