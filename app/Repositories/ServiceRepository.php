<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Http\Request;
use App\Models\Service;
use App\Http\Requests\StoreServiceRequest;
use App\Http\Resources\V1\ServiceResource;
use App\Http\Requests\DestroyServiceRequest;
use App\Http\Requests\SearchServiceRequest;
use App\Http\Requests\UpdateServiceRequest;
use App\Repositories\Contracts\ServiceInterface;
use App\Http\Resources\V1\ServiceResourceCollection;

class ServiceRepository implements ServiceInterface
{
    public function all(Request $request)
    {
        $limit = ($request->limit) ? $request->limit : 10;
        $offset = ($request->offset) ? $request->offset : 0;

        $services = Service::when($request->enabled !== null, function ($q) use ($request) {
                                return $q->where('enabled', $request->enabled);
                            })
                            ->orderBy('name')
                            ->offset($offset)
                            ->limit($limit)
                            ->get();

        return new ServiceResourceCollection($services);
    }

    public function find($id)
    {
        $service = Service::find($id);
        return new ServiceResource($service);
    }

    public function search(SearchServiceRequest $request)
    {
        $perPage = min($request->integer('per_page', 10), 100);

        $services = Service::query()
            ->when($request->has('enabled'), function ($q) use ($request) {
                $q->where('enabled', $request->boolean('enabled'));
            })
            ->when($request->filled('name'), function ($q) use ($request) {
                $q->where('name', 'like', '%' . $request->name . '%');
            })
            ->when($request->filled('slug'), function ($q) use ($request) {
                $q->where('slug', $request->slug);
            })
            ->orderBy('name')
            ->paginate($perPage);

        return new ServiceResourceCollection($services);
    }

    public function update($id, UpdateServiceRequest $request): ServiceResource
    {
        $service = Service::find($id);
        $service->update($request->all());
        return new ServiceResource($service);
    }

    public function store(StoreServiceRequest $request): ServiceResource
    {
        $service = Service::create($request->all());
        return new ServiceResource($service);
    }

    public function destroy(DestroyServiceRequest $request): ServiceResource
    {
        $service = Service::find($request->service_id);
        $service->delete();
        return new ServiceResource($service);
    }
}
