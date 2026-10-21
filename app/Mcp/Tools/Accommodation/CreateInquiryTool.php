<?php

namespace App\Mcp\Tools\Accommodation;

use App\AI\Exceptions\AiToolException;
use App\AI\Support\AiContext;
use App\Models\AccommodationApiKey;
use App\Repositories\Contracts\InquiryInterface;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('create_inquiry')]
#[Description('Deja una consulta del huésped al alojamiento, que la recibe en su sistema y por email y le responde por teléfono. Usala cuando la disponibilidad es "unknown", cuando el huésped quiere reservar o cuando pregunta algo que no figura. Antes de llamarla, confirmá con el huésped su nombre, teléfono, fechas y cantidad de personas. No es una reserva: decile que el alojamiento le va a responder.')]
#[IsIdempotent]
class CreateInquiryTool extends AccommodationTool
{
    public function __construct(private readonly InquiryInterface $inquiries) {}

    public function handle(Request $request): Response
    {
        // La ruta sólo exige `mcp:read`; escribir pide además `inquiry:create`.
        $key = request()->attributes->get('api_key');

        if (! $key instanceof AccommodationApiKey || ! $key->hasAbility('inquiry:create')) {
            return Response::error('Esta credencial no puede dejar consultas. Indicale al huésped que se comunique directamente con el alojamiento.');
        }

        $args = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'lastname' => ['nullable', 'string', 'max:100'],
            'phone' => ['required', 'string', 'min:6', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'checkin' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'checkout' => ['nullable', 'date_format:Y-m-d', 'after:checkin'],
            'adults' => ['required', 'integer', 'min:1', 'max:20'],
            'children' => ['nullable', 'integer', 'min:0', 'max:20'],
            'message' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->respond(function (AiContext $ctx) use ($args) {
            if ($ctx->accommodation === null) {
                throw new AiToolException('El alojamiento no está disponible.');
            }

            $inquiry = $this->inquiries->storeFromAgent($ctx->accommodation, $args);

            return [
                'inquiry_id' => (string) $inquiry->id,
                'status' => 'received',
                'next_step' => 'El alojamiento recibió la consulta y le va a responder al huésped por teléfono.',
            ];
        });
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->required()->description('Nombre del huésped.'),
            'lastname' => $schema->string()->description('Apellido, si lo dio.'),
            'phone' => $schema->string()->required()->description('Teléfono de contacto, con código de área. Por WhatsApp, el número desde el que escribe.'),
            'email' => $schema->string()->format('email')->description('Email, sólo si el huésped lo dio. No lo pidas.'),
            'checkin' => $schema->string()->format('date')->required()->description('Fecha de llegada, YYYY-MM-DD.'),
            'checkout' => $schema->string()->format('date')->description('Fecha de salida, YYYY-MM-DD.'),
            'adults' => $schema->integer()->min(1)->max(20)->required()->description('Cantidad de adultos.'),
            'children' => $schema->integer()->min(0)->max(20)->description('Cantidad de menores (default 0).'),
            'message' => $schema->string()->description('Lo que pregunta o pide el huésped, en una o dos frases.'),
        ];
    }
}
