@component('mail::message')
# Nueva consulta para {{ $about }}

**{{ $guest }}** dejó una consulta{{ $viaAgent ? ' a través del asistente' : '' }}.

@if ($checkin)
- **Fechas:** {{ \Illuminate\Support\Carbon::parse($checkin)->format('d/m/Y') }}@if ($checkout) al {{ \Illuminate\Support\Carbon::parse($checkout)->format('d/m/Y') }}@endif

@endif
- **Huéspedes:** {{ $adults }} {{ $adults === 1 ? 'adulto' : 'adultos' }}@if ($children > 0), {{ $children }} {{ $children === 1 ? 'menor' : 'menores' }}@endif

- **Teléfono:** {{ $phone }}
@if ($email)
- **Email:** {{ $email }}
@endif

@if ($message)
> {{ $message }}
@endif

@component('mail::button', ['url' => $url])
Ver la consulta
@endcomponent

Respondele cuanto antes: una consulta contestada rápido es una reserva más probable.

{{ config('app.name') }}
@endcomponent
