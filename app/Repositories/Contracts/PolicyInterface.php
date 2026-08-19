<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Http\Requests\DestroyPolicyRequest;
use App\Http\Requests\StorePolicyRequest;
use App\Http\Requests\UpdatePolicyRequest;
use Illuminate\Http\Request;

interface PolicyInterface
{
    public function all(Request $request);

    public function find($id);

    public function search($request);

    public function update($id, UpdatePolicyRequest $request);

    public function store(StorePolicyRequest $request);

    public function destroy(DestroyPolicyRequest $request);
}
