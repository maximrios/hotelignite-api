<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Http\Requests\SearchChannelRequest;
use App\Http\Requests\StoreChannelRequest;
use App\Http\Requests\UpdateChannelRequest;
use App\Http\Resources\Admin\ChannelResource as AdminChannelResource;
use App\Http\Resources\Admin\ChannelResourceCollection as AdminChannelResourceCollection;
use App\Http\Resources\V1\ChannelResource;
use App\Http\Resources\V1\ChannelResourceCollection;
use App\Models\Channel;
use App\Repositories\Contracts\ChannelInterface;
use Illuminate\Http\Request;

class ChannelRepository implements ChannelInterface
{
    /**
     * Columnas legacy `NOT NULL DEFAULT '0'` (`email`, `phone`, `web`) y
     * `ota` `tinyint NOT NULL`. Un null explícito desde el panel las rompe, así
     * que se normaliza acá en vez de prohibirlo en la validación: la UI manda
     * null cuando el usuario vacía el campo, y eso tiene que significar "vacío".
     */
    private const LEGACY_NOT_NULL_STRINGS = ['email', 'phone', 'web'];

    public function all(Request $request)
    {
        $limit = $request->limit ?: 15;

        $channels = Channel::when($request->business_type, fn ($q, $v) => $q->where('business_type', $v))
            ->when($request->connection_type, fn ($q, $v) => $q->where('connection_type', $v))
            ->when($request->enabled !== null, fn ($q) => $q->where('enabled', $request->enabled))
            ->when($request->ota !== null, fn ($q) => $q->where('ota', $request->ota))
            ->orderBy('name')
            ->paginate($limit);

        return new ChannelResourceCollection($channels);
    }

    public function find($id)
    {
        $channel = Channel::findOrFail($id);

        return new ChannelResource($channel);
    }

    public function search(SearchChannelRequest $request): AdminChannelResourceCollection
    {
        $perPage = min($request->integer('per_page', 25), 100);

        $channels = Channel::query()
            ->when($request->filled('name'), fn ($q) => $q->where('name', 'like', '%'.$request->name.'%'))
            ->when($request->filled('business_type'), fn ($q) => $q->where('business_type', $request->business_type))
            ->when($request->filled('connection_type'), fn ($q) => $q->where('connection_type', $request->connection_type))
            ->when($request->has('enabled'), fn ($q) => $q->where('enabled', $request->boolean('enabled')))
            ->when($request->has('ota'), fn ($q) => $q->where('ota', $request->boolean('ota')))
            // Vidriera de "disponibles para conectar": `has_client=false` saca
            // los canales de padrón, a los que no se entra por autoservicio.
            ->when($request->has('has_client'), fn ($q) => $request->boolean('has_client')
                ? $q->whereHas('clients')
                : $q->whereDoesntHave('clients'))
            ->withCount(['accommodations', 'clients'])
            ->orderBy('name')
            ->paginate($perPage);

        return new AdminChannelResourceCollection($channels);
    }

    public function store(StoreChannelRequest $request): AdminChannelResource
    {
        $channel = Channel::create($this->normalize($request->validated()));

        // `fresh()` y no el modelo recién creado: los campos con default en la
        // BD (`enabled`, `ota`, los enums) no están en el objeto en memoria, y
        // el cast a boolean los devolvía en `false` aunque la fila diga 1.
        return new AdminChannelResource($channel->fresh()->loadCount(['accommodations', 'clients']));
    }

    /**
     * @param  Channel|int|string  $channel  modelo ya resuelto (admin/v1, con
     *                                       route-model binding) o id crudo (el /api/v1 legacy)
     */
    public function update($channel, UpdateChannelRequest $request): AdminChannelResource
    {
        $channel = $channel instanceof Channel ? $channel : Channel::findOrFail($channel);

        // `validated()` y no `all()`: con `all()` cualquier campo presente en
        // `$fillable` entra al UPDATE aunque no lo valide ninguna regla.
        $channel->update($this->normalize($request->validated()));

        return new AdminChannelResource($channel->fresh()->loadCount(['accommodations', 'clients']));
    }

    public function destroy($id): ChannelResource
    {
        $channel = Channel::findOrFail($id);
        $channel->delete();

        return new ChannelResource($channel);
    }

    /**
     * Baja de `admin/v1`, con el id en la ruta.
     *
     * `channels` no tiene SoftDeletes y `reservations.channel_id` /
     * `inquiries.channel_id` guardan la atribución histórica. Borrar un canal
     * con movimiento no falla en el momento: reaparece como reservas sin origen
     * y el reporte por canal deja de cuadrar hacia atrás. Por eso 409 —el
     * camino para sacar un canal de circulación es `enabled = false`, que
     * conserva el histórico.
     */
    public function remove(Channel $channel): void
    {
        abort_if(
            $channel->clients()->exists(),
            409,
            'El canal está asociado a un client B2B. Desvinculalo antes de eliminarlo.'
        );

        abort_if(
            $channel->accommodations()->exists(),
            409,
            'El canal tiene alojamientos conectados. Desconectalos antes de eliminarlo.'
        );

        abort_if(
            $channel->reservations()->exists() || $channel->inquiries()->exists(),
            409,
            'El canal tiene reservas o consultas asociadas. Deshabilitalo en vez de eliminarlo.'
        );

        $channel->delete();
    }

    /**
     * Convierte los null de las columnas legacy NOT NULL en su equivalente
     * vacío. Ver `LEGACY_NOT_NULL_STRINGS`.
     */
    private function normalize(array $data): array
    {
        foreach (self::LEGACY_NOT_NULL_STRINGS as $field) {
            if (array_key_exists($field, $data) && $data[$field] === null) {
                $data[$field] = '';
            }
        }

        // `ota` y `enabled` son tinyint NOT NULL; sus defaults son distintos
        // (0 y 1), así que un null se resuelve al default de cada columna.
        if (array_key_exists('ota', $data) && $data['ota'] === null) {
            $data['ota'] = false;
        }

        if (array_key_exists('enabled', $data) && $data['enabled'] === null) {
            $data['enabled'] = true;
        }

        return $data;
    }
}
