@component('mail::message')
# Te invitaron a HotelIgnite

@isset($contactName)
Hola {{ $contactName }},
@endisset

@if($pitch)
{{ $pitch }}
@else
Te invitaron a sumar tu alojamiento{{ $accommodationName ? ' **'.$accommodationName.'**' : '' }} a HotelIgnite.
@endif

@if($hasOwner)
Entrá con tu cuenta de HotelIgnite para aceptar o rechazar la invitación. También la vas a ver en el inicio de tu panel.

@component('mail::button', ['url' => $url])
Ver la invitación
@endcomponent
@else
Creá tu cuenta gratis y completá la ficha de tu alojamiento desde el siguiente enlace:

@component('mail::button', ['url' => $url])
Completar mi registro
@endcomponent
@endif

Este enlace vence en {{ $expirationDays }} días.

Si no esperabas esta invitación, podés ignorar este mensaje.

Gracias,<br>
{{ config('app.name') }}
@endcomponent
