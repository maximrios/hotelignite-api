@component('mail::message')
# {{ $isDemo ? 'Pidieron una demo' : 'Quieren empezar gratis' }}

**{{ $lead->name }}** dejó sus datos en la landing.

- **Email:** {{ $lead->email }}
- **Teléfono:** {{ $lead->phone }}
@if ($lead->accommodation_name)
- **Alojamiento:** {{ $lead->accommodation_name }}
@endif
@if ($lead->city)
- **Ciudad:** {{ $lead->city }}
@endif
@if ($lead->rooms)
- **Habitaciones:** {{ $lead->rooms }}
@endif

@if ($lead->message)
> {{ $lead->message }}
@endif

Respondé este mail para escribirle directo.

{{ config('app.name') }}
@endcomponent
