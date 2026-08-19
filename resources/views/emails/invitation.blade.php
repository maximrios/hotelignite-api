@component('mail::message')
# Te invitaron a HotelIgnite

@isset($contactName)
Hola {{ $contactName }},
@endisset

@if($clientName)
**{{ $clientName }}** te invitó a sumar tu alojamiento{{ $accommodationName ? ' **'.$accommodationName.'**' : '' }} a HotelIgnite.
@else
Te invitaron a sumar tu alojamiento{{ $accommodationName ? ' **'.$accommodationName.'**' : '' }} a HotelIgnite.
@endif

Completá tu registro y cargá la ficha de tu alojamiento desde el siguiente enlace:

@component('mail::button', ['url' => $url])
Completar mi registro
@endcomponent

Este enlace vence en {{ $expirationDays }} días.

Si no esperabas esta invitación, podés ignorar este mensaje.

Gracias,<br>
{{ config('app.name') }}
@endcomponent
