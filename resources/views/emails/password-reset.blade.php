{{-- "Olvidé mi contraseña". Ver App\Mail\PasswordResetMail. Tablas + estilos inline a propósito: es lo que sobrevive en Gmail y Outlook. --}}
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<title>Elegí una contraseña nueva</title>
<style>
  @media (max-width: 520px) {
    .m-pad { padding-left: 22px !important; padding-right: 22px !important; }
    .m-h1 { font-size: 25px !important; }
  }
</style>
</head>
<body style="margin:0;padding:0;background:#F3EEE6;">
<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;font-size:1px;line-height:1px;color:#F3EEE6;">
  El enlace vale {{ $minutes }} minutos y sirve una sola vez.
</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#F3EEE6;border-collapse:collapse;">
<tr><td align="center" style="padding:28px 12px;">
  <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;border-collapse:separate;background:#FFFFFF;border:1px solid #E6DED2;border-radius:16px;overflow:hidden;">

    <tr><td class="m-pad" style="background:#15110E;padding:22px 40px;">
      <table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>
        <td style="vertical-align:middle;padding-right:10px;"><img src="{{ $logoUrl }}" width="26" height="26" alt="" style="display:block;border:0;"></td>
        <td style="vertical-align:middle;font-family:'Schibsted Grotesk',Arial,Helvetica,sans-serif;font-size:19px;font-weight:700;letter-spacing:-0.02em;color:#F4EEE8;">HotelIgnite</td>
      </tr></table>
    </td></tr>
    <tr><td style="height:4px;line-height:4px;font-size:0;background:#FF5B1F;background-image:linear-gradient(90deg,#FFA445,#FF5B1F 55%,#F63E1F);">&nbsp;</td></tr>

    <tr><td class="m-pad" style="padding:40px 40px 8px;font-family:'Schibsted Grotesk',Arial,Helvetica,sans-serif;color:#15110E;">
      <div style="font-family:Menlo,Consolas,monospace;font-size:12px;letter-spacing:0.08em;text-transform:uppercase;color:#E2470F;margin-bottom:14px;">Recuperar el acceso</div>
      <h1 class="m-h1" style="margin:0 0 18px;font-size:29px;line-height:1.15;font-weight:800;letter-spacing:-0.03em;color:#15110E;">Hola {{ $firstName }}, elegí una contraseña nueva</h1>
      <p style="margin:0;font-size:16px;line-height:1.6;color:#2A231E;">
        Nos pediste cambiar la contraseña de <strong>{{ $email }}</strong>. Tocá el botón y elegí una nueva; al guardarla entrás directo.
      </p>
    </td></tr>

    <tr><td class="m-pad" style="padding:28px 40px 8px;">
      <table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>
        <td style="border-radius:999px;background:#FF5B1F;">
          <a href="{{ $url }}" style="display:inline-block;padding:15px 30px;font-family:'Schibsted Grotesk',Arial,Helvetica,sans-serif;font-size:16px;font-weight:700;color:#FFFFFF;text-decoration:none;border-radius:999px;">Elegir contraseña nueva</a>
        </td>
      </tr></table>
    </td></tr>

    <tr><td class="m-pad" style="padding:22px 40px 4px;font-family:'Schibsted Grotesk',Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#2A231E;">
      El enlace vale <strong>{{ $minutes }} minutos</strong> y sirve una sola vez. Si se vence, pedí otro desde {{ $appHost }}.
    </td></tr>

    <tr><td class="m-pad" style="padding:26px 40px 8px;">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-top:1px solid #E6DED2;"><tr>
        <td style="padding-top:22px;font-family:'Schibsted Grotesk',Arial,Helvetica,sans-serif;font-size:14px;line-height:1.6;color:#4A413A;">
          <div style="font-weight:700;color:#15110E;margin-bottom:6px;">¿No lo pediste vos?</div>
          Ignorá este correo: tu contraseña actual sigue funcionando y nadie puede cambiarla sin este enlace.<br>
          Nunca te vamos a pedir tu contraseña por email, WhatsApp ni teléfono.
        </td>
      </tr></table>
    </td></tr>

    <tr><td class="m-pad" style="padding:18px 40px 36px;font-family:'Schibsted Grotesk',Arial,Helvetica,sans-serif;font-size:13px;line-height:1.55;color:#6A6058;">
      Si el botón no funciona, copiá este enlace en tu navegador:<br>
      <a href="{{ $url }}" style="color:#E2470F;word-break:break-all;text-decoration:underline;">{{ $url }}</a>
    </td></tr>

    <tr><td class="m-pad" style="background:#FAF7F2;border-top:1px solid #E6DED2;padding:22px 40px;font-family:'Schibsted Grotesk',Arial,Helvetica,sans-serif;font-size:12.5px;line-height:1.6;color:#6A6058;">
      <strong style="color:#15110E;">HotelIgnite</strong> · Gestión hotelera y destinos turísticos<br>
      Recibiste este correo porque alguien pidió recuperar el acceso de esta dirección.
      @if($supportEmail)<br>Ayuda: <a href="mailto:{{ $supportEmail }}" style="color:#6A6058;">{{ $supportEmail }}</a>@endif
    </td></tr>

  </table>
</td></tr>
</table>
</body>
</html>
