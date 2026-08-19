<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tipo de documento del catálogo (habilitación, seguro, bomberos, fiscal…).
 * Es dato sembrado, no lógica. Ver `docs/documents-plan.md`.
 */
class DocumentType extends Model
{
    public const OWNER_ACCOUNT = 'account';

    public const OWNER_ENTITY = 'entity';

    public const ENTITY_ACCOMMODATION = 'accommodation';

    protected $fillable = [
        'slug',
        'name',
        'entity_type',
        'owner_level',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function documents()
    {
        return $this->hasMany(Document::class);
    }

    public function requirements()
    {
        return $this->hasMany(ClientDocumentRequirement::class);
    }
}
