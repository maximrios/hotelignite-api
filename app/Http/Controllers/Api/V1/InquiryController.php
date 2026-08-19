<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StoreInquiryRequest;
use App\Repositories\Contracts\InquiryInterface;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

class InquiryController extends BaseController
{
    protected InquiryInterface $inquiryInterface;

    public function __construct(InquiryInterface $inquiryInterface)
    {
        $this->inquiryInterface = $inquiryInterface;
    }

    public function index(Request $request)
    {
        // Se devuelve la colección directamente (no envuelta en response()->json)
        // para que Laravel use el path Responsable y arme el wrapper { data,
        // links, meta }. Con response()->json() se serializa vía jsonSerialize y
        // se pierde la clave `data` que el frontend espera.
        return $this->inquiryInterface->all($request);
    }

    public function store(StoreInquiryRequest $request)
    {
        return response()->json($this->inquiryInterface->store($request), 201);
    }
}
