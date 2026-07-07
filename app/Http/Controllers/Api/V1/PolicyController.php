<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Policy;
use App\Repositories\Contracts\PolicyInterface;
use App\Http\Requests\StorePolicyRequest;
use App\Http\Resources\V1\PolicyResource;
use App\Http\Requests\SearchPolicyRequest;
use App\Http\Requests\UpdatePolicyRequest;
use App\Http\Requests\DestroyPolicyRequest;
use Illuminate\Routing\Controller as BaseController;

class PolicyController extends BaseController
{

    protected PolicyInterface $policyInterface;

    public function __construct(PolicyInterface $policyInterface)
    {
        $this->policyInterface = $policyInterface;
    }

    public function index(SearchPolicyRequest $request)
    {
        $policies = $this->policyInterface->search($request);
        return response()->json($policies, 200);
    }

    public function show($id)
    {
        $policy = Policy::find($id);
        return new PolicyResource($policy);
    }

    public function update(UpdatePolicyRequest $request, $id)
    {
        $policy = $this->policyInterface->update($id, $request);
        return response()->json($policy, 200);
    }

    public function store(StorePolicyRequest $request)
    {
        $policy = $this->policyInterface->store($request);
        return response()->json($policy, 200);
    }

    public function destroy(DestroyPolicyRequest $request)
    {
        $policy = $this->policyInterface->destroy($request);
        return response()->json([
            'message' => 'Policy deleted successfully',
            'data' => $policy
        ], 200);
    }
}



