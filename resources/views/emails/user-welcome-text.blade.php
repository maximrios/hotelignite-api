Hola {{ $firstName }},

@if($reminder)
Hace unos días creamos tu usuario{{ $clientName ? ' en el panel de '.$clientName : '' }} en HotelIgnite y todavía no creaste tu contraseña.
@else
Creamos tu usuario{{ $clientName ? ' en el panel de '.$clientName : '' }} en HotelIgnite. {{ $pitch }}
@endif

Para entrar falta un solo paso: elegir tu contraseña.

Crear mi contraseña: {{ $url }}

Tu usuario: {{ $email }}
Dónde entrar: {{ $portalHost }}
Validez: hasta el {{ $expiresOn }}. El enlace sirve una sola vez.

@if($createdByName)
Te dio de alta {{ $createdByName }}, del equipo de HotelIgnite.
@endif
@if($supportEmail)
Si tenés dudas, respondé este email o escribinos a {{ $supportEmail }}.
@endif

Nunca te vamos a pedir tu contraseña por email, WhatsApp ni teléfono. Si no esperabas este correo, podés ignorarlo: sin crear la contraseña nadie puede entrar.

HotelIgnite
