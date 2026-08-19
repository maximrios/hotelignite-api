<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Un documento del legajo (bolsa) de un prestador. Polimórfico: cuelga de
 * `Accommodation` o `Account` hoy, de `Restaurant`/`TourOperator` mañana.
 *
 * Nace privado. Un client lo ve si y solo si existe un `DocumentShare` para él.
 * El binario vive en un disco privado (`disk`/`path`), nunca en una URL pública.
 * Ver `docs/documents-plan.md`.
 */
class Document extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'documentable_type',
        'documentable_id',
        'document_type_id',
        'title',
        'disk',
        'path',
        'original_name',
        'mime',
        'size',
        'uploaded_by_user_id',
        'issued_at',
        'expires_at',
    ];

    protected $casts = [
        'size' => 'integer',
        'issued_at' => 'date',
        'expires_at' => 'date',
    ];

    /**
     * Nunca se serializan: son referencias al almacenamiento privado, no datos
     * que el prestador (ni menos un client) deba ver en la respuesta.
     */
    protected $hidden = [
        'disk',
        'path',
    ];

    public function documentable()
    {
        return $this->morphTo();
    }

    public function documentType()
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function shares()
    {
        return $this->hasMany(DocumentShare::class);
    }

    public function uploadedBy()
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    /**
     * ¿Está compartido con este client? (la visibilidad es la existencia del share).
     */
    public function isSharedWith(int $clientId): bool
    {
        return $this->shares()->where('client_id', $clientId)->exists();
    }
}
