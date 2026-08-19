<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Http\Requests\SearchChannelRequest;
use App\Http\Requests\StoreChannelRequest;
use App\Http\Requests\UpdateChannelRequest;
use App\Models\Channel;
use Illuminate\Http\Request;

interface ChannelInterface
{
    public function all(Request $request);

    public function find($id);

    /** Listado de `admin/v1`: pagina, filtra y trae los counts del catálogo. */
    public function search(SearchChannelRequest $request);

    public function store(StoreChannelRequest $request);

    /** @param  Channel|int|string  $channel */
    public function update($channel, UpdateChannelRequest $request);

    public function destroy($id);

    /** Baja de `admin/v1`, con el id en la ruta. Aborta con 409 si está en uso. */
    public function remove(Channel $channel): void;
}
