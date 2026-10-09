<?php

namespace App\Http\Requests\ClientPanel;

use App\Http\Requests\Admin\StorePointOfInterestRequest as AdminStorePointOfInterestRequest;

/** Versión client de `Admin\StorePointOfInterestRequest`, sin los campos de curaduría. */
class StorePointOfInterestRequest extends AdminStorePointOfInterestRequest
{
    public function rules(): array
    {
        return ClientContentFields::strip(parent::rules());
    }
}
