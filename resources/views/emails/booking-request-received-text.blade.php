@if($expired)
Venció la solicitud {{ $code }} de {{ $guestName }} en {{ $hotel }}. Se canceló sola y no había bloqueado habitaciones. Al huésped le avisamos.
@else
Nueva solicitud de reserva {{ $code }} en {{ $hotel }}.

Todavía no bloquea habitaciones: se asigna una cuando la confirmás en el PMS. Antes de confirmar, escribile al huésped con los datos de la reserva y el link de pago (respondiendo este email le llega directo).
@if($deadline)
Vence el {{ $deadline }}. Si no la respondés, se cancela sola.
@endif
@endif

Huésped: {{ $guestName }}
@if($guestEmail)Email: {{ $guestEmail }}
@endif
@if($guestPhone)Teléfono: {{ $guestPhone }}
@endif
Llegada: {{ $checkin }}
Salida: {{ $checkout }} ({{ $nights }} {{ $nights === 1 ? 'noche' : 'noches' }})
@foreach($rooms as $room)
{{ $room['label'] }} · {{ $room['guests'] }} · {{ $room['total'] }}
@endforeach
@foreach($extras as $extra)
Extra: {{ $extra['label'] }} · {{ $extra['detail'] }} · {{ $extra['total'] }}
@endforeach
Total: {{ $total }}
@if($origin)Origen: {{ $origin }}
@endif
@if(count($notes))

Lo que pidió el huésped:
@foreach($notes as $line)
{{ $line }}
@endforeach
@endif
@if(! $expired)

Confirmar o rechazar en el PMS: {{ $url }}
@endif

HotelIgnite · Gestión hotelera y destinos turísticos
