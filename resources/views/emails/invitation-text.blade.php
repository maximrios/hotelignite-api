{{ $contactName ? "Hola {$contactName}," : ($brand ? "Invitación de {$brand['name']}" : 'Te invitaron a HotelIgnite') }}

{{ $pitch ?? 'Te invitaron a sumar tu alojamiento a HotelIgnite.' }}

{{ $hasOwner ? 'Entrá con tu cuenta de HotelIgnite para aceptar o rechazar:' : 'Creá tu cuenta gratis y completá la ficha de tu alojamiento:' }}
{{ $url }}

@if($expiresOn)El enlace vence el {{ $expiresOn }}.
@endif
@if($replyTo && $brand)¿Dudas? Respondé este email y le llega a {{ $brand['name'] }}.
@endif
Si no esperabas esta invitación, podés ignorarla.

--
@if($brand)Enviado en nombre de {{ $brand['name'] }} · @endif
Con la tecnología de HotelIgnite
