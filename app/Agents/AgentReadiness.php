<?php

namespace App\Agents;

use App\Models\Accommodation;
use App\Models\AccommodationDescription;
use App\Models\AccommodationPolicy;
use App\Models\Rate;
use App\Models\Room;
use Illuminate\Support\Facades\DB;

/**
 * Qué le falta a la ficha para que el agente conteste bien.
 *
 * El agente tiene la regla de no inventar: cada dato que falta es una pregunta
 * que termina en "no tengo ese dato" o en una consulta para el hotel. Esto se lo
 * muestra al hotelero en la página de Agentes, con el link a la pantalla donde
 * se completa.
 *
 * `required`: sin eso el agente no puede hacer lo central (cotizar).
 * `recommended`: contesta igual, pero peor.
 */
final class AgentReadiness
{
    /** Ventana en la que se busca inventario y tarifas cargadas. */
    private const HORIZON_DAYS = 60;

    /**
     * @return list<array{key: string, label: string, ok: bool, level: 'required'|'recommended', hint: string, href: string}>
     */
    public static function for(Accommodation $accommodation): array
    {
        $roomTypeIds = $accommodation->roomTypes()->where('max_occupancy', '>', 0)->pluck('id');
        $from = now()->toDateString();
        $to = now()->addDays(self::HORIZON_DAYS)->toDateString();

        $description = AccommodationDescription::query()
            ->where('accommodation_id', $accommodation->id)
            ->where(fn ($q) => $q->whereNotNull('description')->where('description', '<>', '')
                ->orWhere(fn ($q) => $q->whereNotNull('introduction')->where('introduction', '<>', '')))
            ->exists();

        $checks = [
            [
                'key' => 'rooms',
                'label' => 'Tipos de habitación con capacidad',
                'ok' => $roomTypeIds->isNotEmpty(),
                'level' => 'required',
                'hint' => 'Sin habitaciones cargadas el agente no puede decir qué ofrecés ni para cuántas personas.',
                'href' => '/habitaciones',
            ],
            [
                'key' => 'inventory',
                // El cupo sale de las habitaciones físicas libres (`RoomInventory`),
                // como en el motor de reservas. `room_availability` es la grilla de
                // cierres: que esté vacía es lo normal.
                'label' => 'Habitaciones cargadas (inventario)',
                'ok' => $roomTypeIds->isNotEmpty() && Room::query()
                    ->whereIn('room_type_id', $roomTypeIds)
                    ->whereNull('archived_at')
                    ->exists(),
                'level' => 'required',
                'hint' => 'Sin las habitaciones (101, 102…) el agente no sabe cuántas quedan libres y a cada pregunta de fechas contesta que tiene que consultarlo.',
                'href' => '/habitaciones',
            ],
            [
                'key' => 'rates',
                'label' => 'Tarifas de los próximos 60 días',
                'ok' => $roomTypeIds->isNotEmpty() && Rate::query()
                    ->whereIn('room_type_id', $roomTypeIds)
                    ->whereBetween('date', [$from, $to])
                    ->exists(),
                'level' => 'required',
                'hint' => 'Sin precios el agente no puede cotizar: deja la consulta para que respondas vos.',
                'href' => '/tarifas',
            ],
            [
                'key' => 'description',
                'label' => 'Descripción del alojamiento',
                'ok' => $description,
                'level' => 'recommended',
                'hint' => 'Es lo que usa para presentar el lugar cuando alguien pregunta cómo es.',
                'href' => '/hotel?tab=general',
            ],
            [
                'key' => 'services',
                'label' => 'Servicios',
                'ok' => $accommodation->services()->exists(),
                'level' => 'recommended',
                'hint' => 'Wifi, estacionamiento, desayuno, pileta: las preguntas más comunes.',
                'href' => '/hotel?tab=services',
            ],
            [
                'key' => 'policies',
                'label' => 'Políticas (check-in, mascotas, niños, pagos)',
                'ok' => AccommodationPolicy::query()->where('accommodation_id', $accommodation->id)->exists(),
                'level' => 'recommended',
                'hint' => 'Sin políticas, a "¿aceptan mascotas?" contesta que no lo sabe.',
                'href' => '/hotel?tab=policies',
            ],
            [
                'key' => 'cancellation',
                'label' => 'Condiciones de cancelación',
                'ok' => $accommodation->ratePolicies()->exists(),
                'level' => 'recommended',
                'hint' => 'El agente no puede suponer que una reserva se cancela sin cargo.',
                'href' => '/hotel?tab=policies',
            ],
            [
                'key' => 'location',
                'label' => 'Ubicación en el mapa',
                'ok' => DB::table('accommodations')->where('id', $accommodation->id)->whereNotNull('location')->exists(),
                'level' => 'recommended',
                'hint' => 'Con el punto en el mapa puede mandar el link de cómo llegar y lo que hay cerca.',
                'href' => '/hotel?tab=location',
            ],
            [
                'key' => 'images',
                'label' => 'Fotos',
                'ok' => $accommodation->images()->exists(),
                'level' => 'recommended',
                'hint' => 'Para mostrar el lugar en el chat de la web.',
                'href' => '/hotel?tab=gallery',
            ],
        ];

        return $checks;
    }
}
