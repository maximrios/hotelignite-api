{{-- Aviso al hotel con la marca de HotelIgnite. Ver App\Mail\BookingRequestReceivedMail. Mismo armado que user-welcome: tablas + estilos inline. --}}
@php($font = "'Schibsted Grotesk',Arial,Helvetica,sans-serif")
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<title>{{ $expired ? 'Venció la solicitud '.$code : 'Nueva solicitud de reserva '.$code }}</title>
<style>
  @media (max-width: 520px) {
    .m-pad { padding-left: 22px !important; padding-right: 22px !important; }
    .m-h1 { font-size: 24px !important; }
    .m-label { width: 96px !important; }
  }
</style>
</head>
<body style="margin:0;padding:0;background:#F3EEE6;">
<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;font-size:1px;line-height:1px;color:#F3EEE6;">
  @if($expired)
    La solicitud de {{ $guestName }} se canceló sola.
  @else
    {{ $guestName }} · {{ $checkin }} · {{ $total }}. Respondé antes de que venza.
  @endif
</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#F3EEE6;border-collapse:collapse;">
<tr><td align="center" style="padding:28px 12px;">
  <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;border-collapse:separate;background:#FFFFFF;border:1px solid #E6DED2;border-radius:16px;overflow:hidden;">

    <tr><td class="m-pad" style="background:#15110E;padding:22px 40px;">
      <table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>
        <td style="vertical-align:middle;padding-right:10px;"><img src="{{ $logoUrl }}" width="26" height="26" alt="" style="display:block;border:0;"></td>
        <td style="vertical-align:middle;font-family:{!! $font !!};font-size:19px;font-weight:700;letter-spacing:-0.02em;color:#F4EEE8;">HotelIgnite</td>
      </tr></table>
    </td></tr>
    <tr><td style="height:4px;line-height:4px;font-size:0;background:#FF5B1F;background-image:linear-gradient(90deg,#FFA445,#FF5B1F 55%,#F63E1F);">&nbsp;</td></tr>

    <tr><td class="m-pad" style="padding:36px 40px 8px;font-family:{!! $font !!};color:#15110E;">
      <div style="font-family:Menlo,Consolas,monospace;font-size:12px;letter-spacing:0.08em;text-transform:uppercase;color:#E2470F;margin-bottom:14px;">{{ $hotel }} · Motor de reservas</div>
      <h1 class="m-h1" style="margin:0 0 16px;font-size:28px;line-height:1.15;font-weight:800;letter-spacing:-0.03em;color:#15110E;">
        {{ $expired ? 'Venció una solicitud sin responder' : 'Nueva solicitud de '.$guestName }}
      </h1>
      @if($expired)
        <p style="margin:0;font-size:16px;line-height:1.6;color:#2A231E;">La solicitud de <strong>{{ $guestName }}</strong> se canceló sola y al huésped le avisamos. No había bloqueado habitaciones. Si todavía te interesa, podés escribirle respondiendo este email.</p>
      @else
        <p style="margin:0 0 12px;font-size:16px;line-height:1.6;color:#2A231E;">Todavía no bloquea habitaciones: se asigna una cuando la confirmás en el PMS.</p>
        <p style="margin:0;font-size:16px;line-height:1.6;color:#2A231E;"><strong>Antes de confirmar</strong>, escribile al huésped desde tu email con los datos de la reserva y el link de pago. Respondiendo este email le llega directo.</p>
      @endif
    </td></tr>

    @if(! $expired && $deadline)
    <tr><td class="m-pad" style="padding:20px 40px 4px;">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#FFF3E8;border:1px solid #FFD2B3;border-radius:12px;">
        <tr><td style="padding:12px 16px;font-family:{!! $font !!};font-size:14px;line-height:1.5;color:#9A3A0B;">
          Vence el <strong>{{ $deadline }}</strong>. Si no la respondés, se cancela sola.
        </td></tr>
      </table>
    </td></tr>
    @endif

    <tr><td class="m-pad" style="padding:22px 40px 8px;">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#FAF7F2;border:1px solid #E6DED2;border-radius:12px;">
        <tr><td style="padding:18px 20px;font-family:{!! $font !!};font-size:14px;line-height:1.5;color:#15110E;">
          <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
            <tr><td class="m-label" style="padding:4px 0;color:#6A6058;width:120px;vertical-align:top;">Solicitud</td><td style="padding:4px 0;font-weight:700;font-family:Menlo,Consolas,monospace;">{{ $code }}</td></tr>
            <tr><td class="m-label" style="padding:4px 0;color:#6A6058;vertical-align:top;">Huésped</td><td style="padding:4px 0;font-weight:600;">{{ $guestName }}</td></tr>
            @if($guestEmail)<tr><td class="m-label" style="padding:4px 0;color:#6A6058;vertical-align:top;">Email</td><td style="padding:4px 0;word-break:break-all;"><a href="mailto:{{ $guestEmail }}" style="color:#E2470F;">{{ $guestEmail }}</a></td></tr>@endif
            @if($guestPhone)<tr><td class="m-label" style="padding:4px 0;color:#6A6058;vertical-align:top;">Teléfono</td><td style="padding:4px 0;">{{ $guestPhone }}</td></tr>@endif
            <tr><td class="m-label" style="padding:4px 0;color:#6A6058;vertical-align:top;">Llegada</td><td style="padding:4px 0;">{{ ucfirst($checkin) }}</td></tr>
            <tr><td class="m-label" style="padding:4px 0;color:#6A6058;vertical-align:top;">Salida</td><td style="padding:4px 0;">{{ ucfirst($checkout) }} · {{ $nights }} {{ $nights === 1 ? 'noche' : 'noches' }}</td></tr>
            @foreach($rooms as $room)
              <tr><td class="m-label" style="padding:4px 0;color:#6A6058;vertical-align:top;">{{ $loop->first ? 'Habitaciones' : '' }}</td><td style="padding:4px 0;">{{ $room['label'] }} · {{ $room['guests'] }} · {{ $room['total'] }}</td></tr>
            @endforeach
            @foreach($extras as $extra)
              <tr><td class="m-label" style="padding:4px 0;color:#6A6058;vertical-align:top;">{{ $loop->first ? 'Extras' : '' }}</td><td style="padding:4px 0;">{{ $extra['label'] }} · {{ $extra['detail'] }} · {{ $extra['total'] }}</td></tr>
            @endforeach
            <tr><td class="m-label" style="padding:8px 0 4px;color:#6A6058;vertical-align:top;">Total</td><td style="padding:8px 0 4px;font-weight:800;font-size:16px;">{{ $total }}</td></tr>
            @if($origin)<tr><td class="m-label" style="padding:4px 0;color:#6A6058;vertical-align:top;">Origen</td><td style="padding:4px 0;">{{ $origin }}</td></tr>@endif
          </table>
        </td></tr>
      </table>
    </td></tr>

    @if(count($notes))
    <tr><td class="m-pad" style="padding:16px 40px 4px;font-family:{!! $font !!};font-size:14px;line-height:1.6;color:#2A231E;">
      <div style="font-weight:700;color:#15110E;margin-bottom:4px;">Lo que pidió el huésped</div>
      @foreach($notes as $line){{ $line }}@if(! $loop->last)<br>@endif @endforeach
    </td></tr>
    @endif

    @if(! $expired)
    <tr><td class="m-pad" style="padding:26px 40px 8px;">
      <table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>
        <td style="border-radius:999px;background:#FF5B1F;">
          <a href="{{ $url }}" style="display:inline-block;padding:15px 30px;font-family:{!! $font !!};font-size:16px;font-weight:700;color:#FFFFFF;text-decoration:none;border-radius:999px;">Confirmar o rechazar en el PMS</a>
        </td>
      </tr></table>
    </td></tr>
    @endif

    <tr><td class="m-pad" style="padding:18px 40px 32px;font-family:{!! $font !!};font-size:13px;line-height:1.55;color:#6A6058;">
      Al huésped no le avisamos nada al confirmar o rechazar, salvo que marques la casilla en el PMS.
    </td></tr>

    <tr><td class="m-pad" style="background:#FAF7F2;border-top:1px solid #E6DED2;padding:22px 40px;font-family:{!! $font !!};font-size:12.5px;line-height:1.6;color:#6A6058;">
      <strong style="color:#15110E;">HotelIgnite</strong> · Gestión hotelera y destinos turísticos<br>
      Recibiste este correo porque {{ $hotel }} recibe reservas online con HotelIgnite.
    </td></tr>

  </table>
</td></tr>
</table>
</body>
</html>
