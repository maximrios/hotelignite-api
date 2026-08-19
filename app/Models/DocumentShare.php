<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Share = consentimiento explícito del prestador de mostrar un documento a un
 * client. La fila existe ⇔ está compartido. Borrarla = revocar. Ver §3/§7.
 */
class DocumentShare extends Model
{
    protected $fillable = [
        'document_id',
        'client_id',
        'shared_by_user_id',
        'shared_at',
        'verified_at',
        'verified_by_user_id',
    ];

    protected $casts = [
        'shared_at' => 'datetime',
        'verified_at' => 'datetime',
    ];

    public function document()
    {
        return $this->belongsTo(Document::class);
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function sharedBy()
    {
        return $this->belongsTo(User::class, 'shared_by_user_id');
    }
}
