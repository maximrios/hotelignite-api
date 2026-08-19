<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Http\Requests\Admin\StoreAccommodationChannelRequest;
use App\Http\Requests\Admin\UpdateAccommodationChannelRequest;
use App\Models\Accommodation;
use App\Models\AccommodationChannel;
use App\Models\Channel;
use App\Models\Client;
use App\Repositories\Contracts\AccommodationChannelInterface;
use Illuminate\Support\Collection;

/**
 * Los canales de un alojamiento salen de DOS fuentes que no se pisan, y se
 * unen en la lectura en vez de copiarse:
 *
 *  - **Convenio** — derivados de `accommodation_client` con `status = 'active'`:
 *    el hotel entró al padrón de un client por invitación. No los administra él;
 *    para salir hay que salir del padrón.
 *  - **Propio** — filas de `accommodation_channels`: Booking, mostrador, la web.
 *    Esos sí los conecta y desconecta el hotel.
 *
 * La alternativa era una sola tabla sincronizada desde el flujo de invitaciones,
 * pero obligaba a espejar tres estados (`status`, `verified_at`, `enabled`) y
 * cada desincronización es un canal fantasma en el menú del PMS.
 *
 * Un canal de convenio PUEDE tener fila en `accommodation_channels`: ahí la fila
 * no es la membresía sino el overlay de condiciones (comisión negociada, código
 * externo). Por eso ausencia de fila ≠ no conectado.
 */
class AccommodationChannelRepository implements AccommodationChannelInterface
{
    public const SOURCE_AGREEMENT = 'convenio';

    public const SOURCE_OWN = 'propio';

    public function connected(Accommodation $accommodation): Collection
    {
        $overlays = $this->overlays($accommodation);
        $agreements = $this->agreementChannels($accommodation);

        $rows = collect();

        // Primero los de convenio: si un canal está en las dos fuentes, manda
        // el convenio (el hotel no puede desconectarlo por su cuenta).
        foreach ($agreements as $channelId => $client) {
            $rows->push($this->row(
                $accommodation,
                $client->channel,
                $overlays->get($channelId),
                self::SOURCE_AGREEMENT,
                $client
            ));
        }

        foreach ($overlays as $channelId => $overlay) {
            if ($agreements->has($channelId)) {
                continue;
            }

            $rows->push($this->row($accommodation, $overlay->channel, $overlay, self::SOURCE_OWN));
        }

        return $rows->sortBy(fn (AccommodationChannel $row) => $row->channel?->name)->values();
    }

    public function available(Accommodation $accommodation): Collection
    {
        $connected = $this->overlays($accommodation)->keys()
            ->merge($this->agreementChannels($accommodation)->keys())
            ->all();

        return Channel::query()
            ->where('enabled', true)
            // Los canales con client detrás no se conectan por autoservicio: se
            // entra por invitación al padrón. Sin este filtro, cualquier hotel
            // se autoagregaría al canal de un municipio.
            ->whereDoesntHave('clients')
            ->whereNotIn('id', $connected)
            ->orderBy('name')
            ->get();
    }

    public function connect(Accommodation $accommodation, StoreAccommodationChannelRequest $request): AccommodationChannel
    {
        $channel = Channel::findOrFail($request->validated('channel_id'));

        abort_if(
            ! $channel->enabled,
            422,
            'El canal está deshabilitado en el catálogo.'
        );

        abort_if(
            $channel->clients()->exists(),
            422,
            'Este canal pertenece a un client B2B: la conexión se establece aceptando su invitación, no desde acá.'
        );

        abort_if(
            $this->overlays($accommodation)->has($channel->id),
            409,
            'El alojamiento ya está conectado a este canal.'
        );

        $overlay = AccommodationChannel::create([
            'accommodation_id' => $accommodation->id,
            'channel_id' => $channel->id,
        ] + $request->safe()->only(['enabled', 'commission_rate', 'external_code']));

        return $this->row($accommodation, $channel, $overlay->fresh(), self::SOURCE_OWN);
    }

    /**
     * Alta o edición del overlay de condiciones. Es un upsert a propósito: un
     * canal de convenio no tiene fila hasta que el hotel quiere anotarle la
     * comisión, y esa primera edición no puede fallar con un 404.
     */
    public function configure(Accommodation $accommodation, Channel $channel, UpdateAccommodationChannelRequest $request): AccommodationChannel
    {
        $agreements = $this->agreementChannels($accommodation);
        $isAgreement = $agreements->has($channel->id);

        abort_if(
            ! $isAgreement && ! $this->overlays($accommodation)->has($channel->id),
            404,
            'El alojamiento no está conectado a este canal.'
        );

        $overlay = AccommodationChannel::firstOrNew([
            'accommodation_id' => $accommodation->id,
            'channel_id' => $channel->id,
        ]);

        $overlay->fill($request->safe()->only(['enabled', 'commission_rate', 'external_code']));
        $overlay->save();

        return $this->row(
            $accommodation,
            $channel,
            $overlay->fresh(),
            $isAgreement ? self::SOURCE_AGREEMENT : self::SOURCE_OWN,
            $agreements->get($channel->id)
        );
    }

    public function disconnect(Accommodation $accommodation, Channel $channel): void
    {
        abort_if(
            $this->agreementChannels($accommodation)->has($channel->id),
            409,
            'Este canal viene del padrón de un client B2B. Para desconectarlo hay que salir de ese padrón.'
        );

        $overlay = AccommodationChannel::where('accommodation_id', $accommodation->id)
            ->where('channel_id', $channel->id)
            ->first();

        abort_if($overlay === null, 404, 'El alojamiento no está conectado a este canal.');

        $overlay->delete();
    }

    /**
     * Filas de `accommodation_channels` del alojamiento, indexadas por canal.
     *
     * @return Collection<int, AccommodationChannel>
     */
    private function overlays(Accommodation $accommodation): Collection
    {
        return AccommodationChannel::with('channel')
            ->where('accommodation_id', $accommodation->id)
            ->get()
            ->keyBy('channel_id');
    }

    /**
     * Canales que le llegan por padrón, indexados por canal. Sólo cuentan los
     * vínculos `active` — mismo corte que `Accommodation::scopeVisibleTo`.
     *
     * @return Collection<int, Client>
     */
    private function agreementChannels(Accommodation $accommodation): Collection
    {
        return Client::query()
            ->whereNotNull('channel_id')
            ->where('active', true)
            ->whereHas('accommodations', fn ($q) => $q
                ->where('accommodations.id', $accommodation->id)
                ->where('accommodation_client.status', 'active'))
            ->with('channel')
            ->get()
            ->filter(fn (Client $client) => $client->channel !== null)
            ->keyBy('channel_id');
    }

    /**
     * Arma una fila de la unión. Cuando un canal de convenio no tiene overlay
     * todavía, se devuelve un `AccommodationChannel` NO persistido: así el
     * Resource trabaja siempre con la misma forma, y `exists` distingue
     * "conectado sin condiciones propias" de "configurado".
     */
    private function row(
        Accommodation $accommodation,
        ?Channel $channel,
        ?AccommodationChannel $overlay,
        string $source,
        ?Client $client = null
    ): AccommodationChannel {
        $row = $overlay ?? new AccommodationChannel([
            'accommodation_id' => $accommodation->id,
            'channel_id' => $channel?->id,
        ]);

        if ($channel !== null) {
            $row->setRelation('channel', $channel);
        }

        // Atributos de presentación, no columnas: la fila nunca se guarda desde acá.
        $row->source = $source;
        $row->client_id = $client?->id;
        $row->client_name = $client?->name;

        return $row;
    }
}
