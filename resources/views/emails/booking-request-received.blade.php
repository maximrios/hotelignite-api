<x-mail::message>
@if($expired)
# Venció una solicitud sin responder

La solicitud de {{ $reservation->guest->full_name }} en {{ $hotel }} se canceló sola y sus habitaciones quedaron libres. Si todavía te interesa, podés contactar al huésped.
@else
# Nueva solicitud de reserva

{{ $reservation->guest->full_name }} pidió reservar en {{ $hotel }}. Las habitaciones quedan apartadas hasta que confirmes o rechaces la solicitud entera.

@if($deadline)
Si no respondés antes del {{ $deadline }}, se cancela sola.
@endif
@endif

- **Llegada:** {{ $reservation->checkin_date->format('d/m/Y') }}
- **Salida:** {{ $reservation->checkout_date->format('d/m/Y') }}
@foreach($rooms as $room)
- **Habitación {{ $loop->iteration }}** ({{ $room->confirmation_number }}): {{ $room->adults }} adultos, {{ $room->children }} menores
@endforeach
- **Total:** {{ $reservation->currency }} {{ number_format($rooms->sum('total_amount') / 100, 2, ',', '.') }}
- **Teléfono:** {{ $reservation->guest->phone }}
- **Email:** {{ $reservation->guest->email }}
@if($reservation->special_requests)
- **Pedido especial:** {{ $reservation->special_requests }}
@endif

<x-mail::button :url="$url">
Ver en el PMS
</x-mail::button>
</x-mail::message>
