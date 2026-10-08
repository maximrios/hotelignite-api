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
     * Un solo upsert indexado sobre `(client_id, date)`: Laravel lo compila a
     * `ON CONFLICT ... DO UPDATE` en Postgres y a `ON DUPLICATE KEY UPDATE` en MySQL.
     */
    public static function hit(int $clientId): void
    {
        $now = now();

        DB::table('client_api_usage')->upsert(
            [[
                'client_id' => $clientId,
                'date' => $now->toDateString(),
                'count' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['client_id', 'date'],
            [
                'count' => DB::raw('client_api_usage.count + 1'),
                'updated_at' => $now,
            ]
        );
    }
}
