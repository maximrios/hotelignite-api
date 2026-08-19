<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Http\Requests\Admin\StoreAccommodationChannelRequest;
use App\Http\Requests\Admin\UpdateAccommodationChannelRequest;
use App\Models\Accommodation;
use App\Models\AccommodationChannel;
use App\Models\Channel;
use Illuminate\Support\Collection;

interface AccommodationChannelInterface
{
    /** Canales conectados: la unión de los propios y los que vienen por padrón. */
    public function connected(Accommodation $accommodation): Collection;

    /** Vidriera: catálogo habilitado, sin client detrás y todavía no conectado. */
    public function available(Accommodation $accommodation): Collection;

    public function connect(Accommodation $accommodation, StoreAccommodationChannelRequest $request): AccommodationChannel;

    public function configure(Accommodation $accommodation, Channel $channel, UpdateAccommodationChannelRequest $request): AccommodationChannel;

    public function disconnect(Accommodation $accommodation, Channel $channel): void;
}
