{{-- Invitación de un client a un hotelero. Ver App\Mail\InvitationMail. Doble marca: arriba el client, abajo "con la tecnología de HotelIgnite". Tablas + estilos inline: es lo que sobrevive en Gmail y Outlook. --}}
@php
  $clientName = $brand['name'] ?? null;
  $title = $hasOwner ? 'Tenés una invitación' : 'Sumá tu alojamiento';
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<title>{{ $clientName ? "Invitación de {$clientName}" : 'Invitación a HotelIgnite' }}</title>
<style>
  @media (max-width: 520px) {
    .m-pad { padding-left: 22px !important; padding-right: 22px !important; }
    .m-h1 { font-size: 25px !important; }
  }
</style>
</head>
<body style="margin:0;padding:0;background:#F3EEE6;">
<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;font-size:1px;line-height:1px;color:#F3EEE6;">
  {{ $pitch ?? 'Te invitaron a sumar tu alojamiento a HotelIgnite.' }}
</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#F3EEE6;border-collapse:collapse;">
<tr><td align="center" style="padding:28px 12px;">
  <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;border-collapse:separate;background:#FFFFFF;border:1px solid #E6DED2;border-radius:16px;overflow:hidden;">

    {{-- Encabezado del client, sobre blanco: casi todos los logos institucionales están pensados para fondo claro. --}}
    <tr><td class="m-pad" style="padding:26px 40px 22px;">
      @if($brand && $brand['logo_url'])
        <img src="{{ $brand['logo_url'] }}" height="48" alt="{{ $clientName }}" style="display:block;border:0;height:48px;width:auto;max-width:260px;">
      @elseif($brand)
        <table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>
          <td style="width:44px;height:44px;border-radius:999px;background:#15110E;text-align:center;vertical-align:middle;font-family:'Schibsted Grotesk',Arial,Helvetica,sans-serif;font-size:16px;font-weight:700;color:#FFFFFF;">{{ $brand['initials'] }}</td>
          <td style="padding-left:12px;vertical-align:middle;font-family:'Schibsted Grotesk',Arial,Helvetica,sans-serif;font-size:17px;font-weight:700;color:#15110E;">{{ $clientName }}</td>
        </tr></table>
      @else
        <table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>
          <td style="vertical-align:middle;padding-right:10px;"><img src="{{ $markUrl }}" width="26" height="26" alt="" style="display:block;border:0;"></td>
          <td style="vertical-align:middle;font-family:'Schibsted Grotesk',Arial,Helvetica,sans-serif;font-size:19px;font-weight:700;color:#15110E;">HotelIgnite</td>
        </tr></table>
      @endif
    </td></tr>
    <tr><td style="height:4px;line-height:4px;font-size:0;background:#FF5B1F;background-image:linear-gradient(90deg,#FFA445,#FF5B1F 55%,#F63E1F);">&nbsp;</td></tr>

    <tr><td class="m-pad" style="padding:36px 40px 8px;font-family:'Schibsted Grotesk',Arial,Helvetica,sans-serif;color:#15110E;">
      @if($clientName)
      <div style="font-family:Menlo,Consolas,monospace;font-size:12px;letter-spacing:0.08em;text-transform:uppercase;color:#E2470F;margin-bottom:14px;">Invitación de {{ $clientName }}</div>
      @endif
      <h1 class="m-h1" style="margin:0 0 18px;font-size:28px;line-height:1.2;font-weight:700;letter-spacing:-0.02em;">{{ $contactName ? "Hola {$contactName}" : $title }}</h1>
      <p style="margin:0 0 14px;font-size:16px;line-height:1.6;color:#2A231E;">
        @if($pitch)
          {{ $pitch }}
        @else
          Te invitaron a sumar {{ $accommodationName ? $accommodationName : 'tu alojamiento' }} a HotelIgnite.
        @endif
      </p>
      <p style="margin:0;font-size:16px;line-height:1.6;color:#2A231E;">
        @if($hasOwner)
          Entrá con tu cuenta de HotelIgnite para aceptar o rechazar. También la vas a ver en el inicio de tu panel.
        @else
          Creá tu cuenta gratis y completá la ficha de tu alojamiento. Te lleva unos minutos.
        @endif
      </p>
    </td></tr>

    <tr><td class="m-pad" style="padding:28px 40px 8px;">
      <table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>
        <td style="border-radius:999px;background:#FF5B1F;">
          <a href="{{ $url }}" style="display:inline-block;padding:15px 30px;font-family:'Schibsted Grotesk',Arial,Helvetica,sans-serif;font-size:16px;font-weight:700;color:#FFFFFF;text-decoration:none;border-radius:999px;">{{ $hasOwner ? 'Ver la invitación' : 'Completar mi registro' }}</a>
        </td>
      </tr></table>
    </td></tr>

    <tr><td class="m-pad" style="padding:22px 40px 4px;font-family:'Schibsted Grotesk',Arial,Helvetica,sans-serif;font-size:14px;line-height:1.6;color:#4A413A;">
      @if($expiresOn)El enlace vence el <strong>{{ $expiresOn }}</strong>.@endif
      @if($replyTo && $clientName) ¿Dudas? Respondé este email y le llega a {{ $clientName }}.@endif
      <br>Si no esperabas esta invitación, podés ignorarla.
    </td></tr>

    <tr><td class="m-pad" style="padding:18px 40px 32px;font-family:'Schibsted Grotesk',Arial,Helvetica,sans-serif;font-size:13px;line-height:1.55;color:#6A6058;">
      Si el botón no funciona, copiá este enlace en tu navegador:<br>
      <a href="{{ $url }}" style="color:#E2470F;word-break:break-all;text-decoration:underline;">{{ $url }}</a>
    </td></tr>

    <tr><td class="m-pad" style="background:#FAF7F2;border-top:1px solid #E6DED2;padding:18px 40px;">
      <table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>
        <td style="vertical-align:middle;padding-right:8px;"><img src="{{ $markUrl }}" width="16" height="16" alt="" style="display:block;border:0;"></td>
        <td style="vertical-align:middle;font-family:'Schibsted Grotesk',Arial,Helvetica,sans-serif;font-size:12.5px;line-height:1.5;color:#6A6058;">
          @if($clientName)Enviado en nombre de {{ $clientName }} · @endif Con la tecnología de <strong style="color:#15110E;">HotelIgnite</strong>
        </td>
      </tr></table>
    </td></tr>

  </table>
</td></tr>
</table>
</body>
</html>
