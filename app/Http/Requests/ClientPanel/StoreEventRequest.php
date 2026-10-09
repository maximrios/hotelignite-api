<?php

namespace App\Http\Requests\ClientPanel;

use App\Http\Requests\Admin\StoreEventRequest as AdminStoreEventRequest;

/** Versión client de `Admin\StoreEventRequest`, sin los campos de curaduría. */
class StoreEventRequest extends AdminStoreEventRequest
{
    public function rules(): array
    {
        return ClientContentFields::strip(parent::rules());
    }
}
