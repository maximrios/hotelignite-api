<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StoreLeadRequest;
use App\Mail\LeadReceivedMail;
use App\Models\Lead;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Mail;

/**
 * Contactos de la landing pública (`web/`). Público: guarda el prospecto y
 * avisa al staff si `api.leads_notify_email` está configurado.
 */
class LeadController extends BaseController
{
    public function store(StoreLeadRequest $request): JsonResponse
    {
        $lead = Lead::create($request->safe()->except(['website', 'cf-turnstile-response']));

        $notify = (string) config('api.leads_notify_email');
        if ($notify !== '') {
            Mail::to($notify)->queue(new LeadReceivedMail($lead));
        }

        return response()->json([
            'message' => $lead->type === Lead::TYPE_DEMO
                ? 'Recibimos tu pedido. Te contactamos para coordinar la demo.'
                : 'Recibimos tus datos. Te contactamos para activar tu cuenta.',
        ], 201);
    }
}
