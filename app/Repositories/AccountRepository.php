<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Models\Account;
use App\Http\Requests\StoreAccountRequest;
use App\Http\Requests\UpdateAccountRequest;
use App\Http\Resources\V1\AccountResource;
use App\Repositories\Contracts\AccountInterface;

class AccountRepository implements AccountInterface
{
    public function all(Request $request)
    {
        $limit  = $request->limit  ?? 15;
        $offset = $request->offset ?? 0;

        $accounts = Account::with(['plan', 'accountType'])
            ->when($request->account_type_id, fn($q, $v) => $q->where('account_type_id', $v))
            ->when($request->plan_id,         fn($q, $v) => $q->where('plan_id', $v))
            ->when(isset($request->active),   fn($q)     => $q->where('active', $request->active))
            ->when($request->search,          fn($q, $v) => $q->where('name', 'like', "%{$v}%"))
            ->orderBy('name')
            ->offset($offset)
            ->limit($limit)
            ->get();

        $total = Account::when($request->account_type_id, fn($q, $v) => $q->where('account_type_id', $v))
            ->when($request->plan_id, fn($q, $v) => $q->where('plan_id', $v))
            ->count();

        return [
            'data' => AccountResource::collection($accounts),
            'meta' => ['total' => $total, 'offset' => $offset, 'limit' => $limit],
        ];
    }

    public function find($id)
    {
        $account = Account::with(['plan', 'accountType', 'accommodations'])->findOrFail($id);
        return new AccountResource($account);
    }

    public function store(StoreAccountRequest $request)
    {
        $data = $request->validated();
        $data['token'] = Str::random(40);
        $account = Account::create($data);
        $account->load(['plan', 'accountType']);
        return new AccountResource($account);
    }

    public function update($id, UpdateAccountRequest $request)
    {
        $account = Account::findOrFail($id);
        $account->update($request->validated());
        $account->load(['plan', 'accountType']);
        return new AccountResource($account);
    }

    public function destroy(Request $request)
    {
        $account = Account::findOrFail($request->account_id);
        $account->delete();
        return new AccountResource($account);
    }
}
