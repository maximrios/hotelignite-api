<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Uso diario de la API por client. Fuente para facturación por volumen.
 */
class ClientApiUsage extends Model
{
    protected $table = 'client_api_usage';

    protected $fillable = [
        'client_id',
        'date',
        'count',
    ];

    protected $casts = [
        'date' => 'date',
        'count' => 'integer',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Incrementa (atómicamente) el contador de hoy para el client dado.
     * Un solo upsert indexado: `INSERT ... ON DUPLICATE KEY UPDATE count = count + 1`.
     */
    public static function hit(int $clientId): void
    {
        $now = now();

        DB::statement(
            'INSERT INTO client_api_usage (client_id, date, count, created_at, updated_at)
             VALUES (?, ?, 1, ?, ?)
             ON DUPLICATE KEY UPDATE count = count + 1, updated_at = VALUES(updated_at)',
            [$clientId, $now->toDateString(), $now, $now]
        );
    }
}
