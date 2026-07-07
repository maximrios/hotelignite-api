<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Http\Request;
use App\Models\Policy;
use App\Http\Requests\StorePolicyRequest;
use App\Http\Resources\V1\PolicyResource;
use App\Http\Requests\DestroyPolicyRequest;
use App\Http\Requests\UpdatePolicyRequest;
use App\Repositories\Contracts\PolicyInterface;
use App\Http\Resources\V1\PolicyResourceCollection;

class PolicyRepository implements PolicyInterface
{
    public function all(Request $request)
    {
        $limit = ($request->limit) ? $request->limit : 10;
        $offset = ($request->offset) ? $request->offset : 0;

        $policies = Policy::when($request->enabled !== null, function ($q) use ($request) {
                                return $q->where('enabled', $request->enabled);
                            })
                            ->orderBy('name')
                            ->offset($offset)
                            ->limit($limit)
                            ->get();

        return new PolicyResourceCollection($policies);
    }

    public function find($id)
    {
        $policy = Policy::find($id);
        return new PolicyResource($policy);
    }

    public function search($request)
    {
        $limit = ($request->limit) ? $request->limit : 10;
        $offset = ($request->offset) ? $request->offset : 0;

        $policies = Policy::when($request->enabled !== null, function ($q) use ($request) {
                                return $q->where('enabled', $request->enabled);
                            })
                            ->when($request->name, function ($q, $name) {
                                return $q->where('name', 'like', "%{$name}%");
                            })
                            ->orderBy('name')
                            ->offset($offset)
                            ->limit($limit)
                            ->paginate();

        return new PolicyResourceCollection($policies);
    }

    public function update($id, UpdatePolicyRequest $request): PolicyResource
    {
        $policy = Policy::find($id);
        $policy->update($request->all());
        return new PolicyResource($policy);
    }

    public function store(StorePolicyRequest $request): PolicyResource
    {
        $policy = Policy::create($request->all());
        return new PolicyResource($policy);
    }

    public function destroy(DestroyPolicyRequest $request): PolicyResource
    {
        $policy = Policy::find($request->policy_id);
        $policy->delete();
        return new PolicyResource($policy);
    }
}



