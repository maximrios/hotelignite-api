<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\AccountType;
use Illuminate\Routing\Controller as BaseController;

class AccountTypeController extends BaseController
{
    public function index()
    {
        return response()->json(AccountType::orderBy('name')->get(), 200);
    }
}
