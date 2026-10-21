@component('mail::message')
# Confirmá tu email

Hola {{ $name }},

Recibimos el registro de **{{ $agencyName }}** en HotelIgnite. Para entrar al portal, confirmá que este email es tuyo:

@component('mail::button', ['url' => $url])
Confirmar mi email
@endcomponent

El enlace vence en {{ $hours }} horas.

Después vamos a revisar los datos de la agencia (CUIT y legajo). Hasta que la verifiquemos vas a poder usar el portal, pero no enviar invitaciones a alojamientos.

Si no te registraste, ignorá este mensaje.

Gracias,<br>
{{ config('app.name') }}
@endcomponent
