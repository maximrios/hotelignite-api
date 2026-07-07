<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use App\Http\Requests\StoreAccountRequest;
use App\Http\Requests\UpdateAccountRequest;
use Illuminate\Routing\Controller as BaseController;
use App\Repositories\Contracts\AccountInterface;

class AccountController extends BaseController
{
    protected AccountInterface $accountInterface;

    public function __construct(AccountInterface $accountInterface)
    {
        $this->accountInterface = $accountInterface;
    }

    public function index(Request $request)
    {
        return response()->json($this->accountInterface->all($request), 200);
    }

    public function show($id)
    {
        return response()->json($this->accountInterface->find($id), 200);
    }

    public function store(StoreAccountRequest $request)
    {
        $account = $this->accountInterface->store($request);
        return response()->json(['message' => 'Account created successfully', 'data' => $account], 201);
    }

    public function update(UpdateAccountRequest $request, $id)
    {
        $account = $this->accountInterface->update($id, $request);
        return response()->json(['message' => 'Account updated successfully', 'data' => $account], 200);
    }

    public function destroy(Request $request)
    {
        $account = $this->accountInterface->destroy($request);
        return response()->json(['message' => 'Account deleted successfully', 'data' => $account], 200);
    }
}
