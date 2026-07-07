<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use Illuminate\Http\Request;
use App\Http\Requests\StoreAccountRequest;
use App\Http\Requests\UpdateAccountRequest;

interface AccountInterface
{
    public function all(Request $request);
    public function find($id);
    public function store(StoreAccountRequest $request);
    public function update($id, UpdateAccountRequest $request);
    public function destroy(Request $request);
}
