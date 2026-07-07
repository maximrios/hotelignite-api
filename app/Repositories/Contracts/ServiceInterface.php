<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use Illuminate\Http\Request;
use App\Http\Requests\StoreServiceRequest;
use App\Http\Requests\UpdateServiceRequest;
use App\Http\Requests\DestroyServiceRequest;
use App\Http\Requests\SearchServiceRequest;

interface ServiceInterface
{
    public function all(Request $request);
    public function find($id);
    public function search(SearchServiceRequest $request);

    public function update($id, UpdateServiceRequest $request);
    public function store(StoreServiceRequest $request);
    public function destroy(DestroyServiceRequest $request);
}
