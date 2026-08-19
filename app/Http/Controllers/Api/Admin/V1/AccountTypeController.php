<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Resources\Admin\AccountTypeResource;
use App\Models\AccountType;
use Illuminate\Routing\Controller as BaseController;

/**
 * Catálogo de tipos de cuenta. Son 4 filas fijas: se devuelven todas, sin
 * paginar ni buscar.
 */
class AccountTypeController extends BaseController
{
    public function index()
    {
        return AccountTypeResource::collection(AccountType::orderBy('name')->get());
    }
}
