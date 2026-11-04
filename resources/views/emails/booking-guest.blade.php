<x-mail::message>
@if($kind === 'confirmed')
# Tu reserva está confirmada

{{ $hotel }} confirmó tu reserva.
@elseif($kind === 'declined')
# No pudimos confirmar tu solicitud

{{ $hotel }} no pudo confirmar tu solicitud para esas fechas.
@elseif($kind === 'expired')
# Tu solicitud venció

{{ $hotel }} no llegó a responderla a tiempo, así que se canceló sola. No se te cobró nada y podés volver a pedirla o comunicarte con el hotel.
@else
# Recibimos tu solicitud

Todavía no es una reserva confirmada. {{ $hotel }} la va a revisar y te va a responder.

@if($deadline)
Si no hay respuesta antes del {{ $deadline }}, se cancela sola y no se te cobra nada.
@endif
@endif

- **Llegada:** {{ $reservation->checkin_date->format('d/m/Y') }}
- **Salida:** {{ $reservation->checkout_date->format('d/m/Y') }}
@foreach($rooms as $room)
- **Habitación {{ $loop->iteration }}** ({{ $room->confirmation_number }}): {{ $room->adults }} adultos, {{ $room->children }} menores, {{ $room->currency }} {{ number_format($room->total_amount / 100, 2, ',', '.') }}
@endforeach
- **Total:** {{ $reservation->currency }} {{ number_format($rooms->sum('total_amount') / 100, 2, ',', '.') }}

@if($contact)
Por cualquier duda, comunicate con el hotel: {{ $contact }}.
@endif
</x-mail::message>
