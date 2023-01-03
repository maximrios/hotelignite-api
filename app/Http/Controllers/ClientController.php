<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Laravel\Passport\Client;

class ClientController extends Controller
{
    
    public function index(Request $request)
    {
        $clients = Client::all();

        return view('vendor.passport.clients.index', [
            'clients' => $clients,
        ]);
    }

    
}
