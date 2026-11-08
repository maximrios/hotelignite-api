{{ $hotel }}

@if($kind === 'confirmed')
Tu reserva está confirmada. {{ $hotel }} confirmó tu reserva.
@elseif($kind === 'declined')
No pudimos confirmar tu solicitud. {{ $hotel }} no tiene lugar para esas fechas. No se te cobró nada.
@elseif($kind === 'expired')
Tu solicitud venció: {{ $hotel }} no llegó a responderla a tiempo y se canceló sola. No se te cobró nada.
@else
Recibimos tu solicitud. Todavía no es una reserva confirmada: {{ $hotel }} la revisa y te responde por email con los datos para pagar.
@if($deadline)
Si no hay respuesta antes del {{ $deadline }}, se cancela sola.
@endif
@endif

{{ $kind === 'confirmed' ? 'Código de reserva' : 'Número de solicitud' }}: {{ $code }}
Llegada: {{ $checkin }}
Salida: {{ $checkout }}
@foreach($rooms as $room)
{{ $room['label'] }} ({{ $room['guests'] }}): {{ $room['total'] }}
@endforeach
@foreach($extras as $extra)
{{ $extra['label'] }} ({{ $extra['detail'] }}): {{ $extra['total'] }}
@endforeach
Total: {{ $total }}
@if(count($notes))

Lo que le pediste al hotel:
@foreach($notes as $line)
{{ $line }}
@endforeach
@endif

¿Dudas? Respondé este email y le llega a {{ $hotel }}.@if($phone) Teléfono: {{ $phone }}.@endif

@if($address){{ $hotel }} · {{ $address }}@endif

Reservas con HotelIgnite
