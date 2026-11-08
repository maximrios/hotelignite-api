{{-- Mail al huésped con la marca del hotel. Ver App\Mail\BookingGuestMail. Tablas + estilos inline: es lo que sobrevive en Gmail y Outlook. --}}
@php
  $accent = '#1E3A5F';
  $font = "'Helvetica Neue',Arial,Helvetica,sans-serif";
  [$eyebrow, $title, $lead, $icon, $iconBg, $iconFg] = match ($kind) {
    'confirmed' => ['Reserva confirmada', 'Tu reserva está confirmada', $hotel.' confirmó tu reserva. Te esperamos.', '✓', '#E4F3EA', '#1F7A48'],
    'declined' => ['Solicitud no confirmada', 'No pudimos confirmar tu solicitud', $hotel.' no tiene lugar para esas fechas. No se te cobró nada.', '!', '#FDF1DC', '#9A5B0B'],
    'expired' => ['Solicitud vencida', 'Tu solicitud venció', $hotel.' no llegó a responderla a tiempo, así que se canceló sola. No se te cobró nada.', '!', '#FDF1DC', '#9A5B0B'],
    default => ['Solicitud recibida', ($guestFirstName ? '¡Gracias, '.$guestFirstName.'! ' : '').'Recibimos tu solicitud', 'Todavía no es una reserva confirmada: '.$hotel.' la revisa y te responde por email con los datos para pagar.', '✓', '#E4F3EA', '#1F7A48'],
  };
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<title>{{ $subject }}</title>
<style>
  @media (max-width: 520px) {
    .m-pad { padding-left: 22px !important; padding-right: 22px !important; }
    .m-h1 { font-size: 24px !important; }
  }
</style>
</head>
<body style="margin:0;padding:0;background:#F6F5F2;">
<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;font-size:1px;line-height:1px;color:#F6F5F2;">
  {{ $lead }} Solicitud {{ $code }}.
</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#F6F5F2;border-collapse:collapse;">
<tr><td align="center" style="padding:28px 12px;">
  <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;border-collapse:separate;background:#FFFFFF;border:1px solid #E5E2DC;border-radius:14px;overflow:hidden;">

    {{-- Marca del hotel --}}
    <tr><td class="m-pad" style="padding:22px 36px;border-bottom:1px solid #E5E2DC;">
      <table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>
        @if($logo)
          <td style="vertical-align:middle;padding-right:12px;"><img src="{{ $logo }}" height="40" alt="{{ $hotel }}" style="display:block;border:0;height:40px;max-width:160px;"></td>
        @else
          <td style="vertical-align:middle;padding-right:12px;">
            <table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td width="38" height="38" align="center" style="width:38px;height:38px;border-radius:10px;background:{{ $accent }};font-family:{!! $font !!};font-size:16px;font-weight:700;color:#FFFFFF;">{{ $initial }}</td></tr></table>
          </td>
        @endif
        <td style="vertical-align:middle;font-family:{!! $font !!};">
          <div style="font-size:17px;font-weight:700;color:#1B1A18;">{{ $hotel }}</div>
          @if($address)<div style="font-size:12.5px;color:#68645D;">{{ $address }}</div>@endif
        </td>
      </tr></table>
    </td></tr>

    <tr><td class="m-pad" align="center" style="padding:36px 36px 8px;font-family:{!! $font !!};color:#1B1A18;text-align:center;">
      <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center"><tr><td width="56" height="56" align="center" style="width:56px;height:56px;border-radius:28px;background:{{ $iconBg }};color:{{ $iconFg }};font-size:26px;font-weight:700;">{{ $icon }}</td></tr></table>
      <div style="font-size:12px;letter-spacing:0.08em;text-transform:uppercase;color:{{ $accent }};margin:18px 0 10px;font-weight:600;">{{ $eyebrow }}</div>
      <h1 class="m-h1" style="margin:0 0 12px;font-size:27px;line-height:1.2;font-weight:700;letter-spacing:-0.02em;color:#1B1A18;">{{ $title }}</h1>
      <p style="margin:0;font-size:15.5px;line-height:1.6;color:#4A4741;">{{ $lead }}</p>
      @if($kind === 'received' && $deadline)
        <p style="margin:10px 0 0;font-size:14px;line-height:1.6;color:#68645D;">Si no hay respuesta antes del <strong style="color:#1B1A18;">{{ $deadline }}</strong>, se cancela sola.</p>
      @endif
    </td></tr>

    <tr><td class="m-pad" align="center" style="padding:20px 36px 4px;">
      <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center"><tr>
        <td style="padding:9px 16px;border-radius:10px;background:#FAF9F7;border:1px solid #E5E2DC;font-family:{!! $font !!};font-size:13px;color:#68645D;">
          {{ $kind === 'confirmed' ? 'Código de reserva' : 'Número de solicitud' }}&nbsp;&nbsp;<strong style="font-family:Menlo,Consolas,monospace;font-size:15px;letter-spacing:0.04em;color:#1B1A18;">{{ $code }}</strong>
        </td>
      </tr></table>
    </td></tr>

    {{-- Detalle --}}
    <tr><td class="m-pad" style="padding:24px 36px 8px;">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #E5E2DC;border-radius:12px;border-collapse:separate;">
        <tr>
          <td width="50%" style="padding:12px 16px;border-right:1px solid #E5E2DC;font-family:{!! $font !!};">
            <div style="font-size:11.5px;color:#68645D;">Llegada</div>
            <div style="font-size:14px;font-weight:600;color:#1B1A18;">{{ ucfirst($checkin) }}</div>
          </td>
          <td width="50%" style="padding:12px 16px;font-family:{!! $font !!};">
            <div style="font-size:11.5px;color:#68645D;">Salida</div>
            <div style="font-size:14px;font-weight:600;color:#1B1A18;">{{ ucfirst($checkout) }}</div>
          </td>
        </tr>
      </table>
    </td></tr>
    <tr><td class="m-pad" style="padding:8px 36px 4px;font-family:{!! $font !!};">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
        @foreach($rooms as $room)
          <tr>
            <td style="padding:10px 0 2px;font-size:14.5px;color:#1B1A18;">{{ $room['label'] }}</td>
            <td align="right" style="padding:10px 0 2px;font-size:14.5px;color:#1B1A18;white-space:nowrap;">{{ $room['total'] }}</td>
          </tr>
          <tr><td colspan="2" style="padding:0 0 10px;font-size:12.5px;color:#68645D;border-bottom:1px solid #EFECE6;">{{ $room['guests'] }} · {{ $nights }} {{ $nights === 1 ? 'noche' : 'noches' }}</td></tr>
        @endforeach
        @foreach($extras as $extra)
          <tr>
            <td style="padding:10px 0 2px;font-size:14.5px;color:#1B1A18;">{{ $extra['label'] }}</td>
            <td align="right" style="padding:10px 0 2px;font-size:14.5px;color:#1B1A18;white-space:nowrap;">{{ $extra['total'] }}</td>
          </tr>
          <tr><td colspan="2" style="padding:0 0 10px;font-size:12.5px;color:#68645D;border-bottom:1px solid #EFECE6;">Extra · {{ $extra['detail'] }}</td></tr>
        @endforeach
        <tr>
          <td style="padding:14px 0 0;font-size:18px;font-weight:700;color:#1B1A18;">Total</td>
          <td align="right" style="padding:14px 0 0;font-size:18px;font-weight:700;color:#1B1A18;white-space:nowrap;">{{ $total }}</td>
        </tr>
      </table>
    </td></tr>

    @if(count($notes))
    <tr><td class="m-pad" style="padding:20px 36px 4px;">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#FAF9F7;border:1px solid #E5E2DC;border-radius:12px;">
        <tr><td style="padding:14px 16px;font-family:{!! $font !!};font-size:13.5px;line-height:1.6;color:#4A4741;">
          <div style="font-weight:600;color:#1B1A18;margin-bottom:4px;">Lo que le pediste al hotel</div>
          @foreach($notes as $line){{ $line }}@if(! $loop->last)<br>@endif @endforeach
        </td></tr>
      </table>
    </td></tr>
    @endif

    @if($kind === 'received')
    <tr><td class="m-pad" style="padding:22px 36px 4px;font-family:{!! $font !!};font-size:14px;line-height:1.6;color:#4A4741;">
      <strong style="color:#1B1A18;">Cómo sigue:</strong> el hotel revisa la disponibilidad y te escribe a este email. Si confirma, te manda cómo pagar. No se cobró nada todavía.
    </td></tr>
    @endif

    @if($web)
    <tr><td class="m-pad" align="center" style="padding:26px 36px 4px;">
      <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center"><tr>
        <td style="border-radius:10px;background:{{ $accent }};">
          <a href="{{ $web }}" style="display:inline-block;padding:13px 26px;font-family:{!! $font !!};font-size:15px;font-weight:600;color:#FFFFFF;text-decoration:none;border-radius:10px;">Visitar la web de {{ $hotel }}</a>
        </td>
      </tr></table>
    </td></tr>
    @endif

    <tr><td class="m-pad" style="padding:26px 36px 30px;font-family:{!! $font !!};font-size:14px;line-height:1.6;color:#4A4741;">
      ¿Dudas? Respondé este email y le llega a {{ $hotel }}@if($phone), o llamá al <a href="tel:{{ $phone }}" style="color:{{ $accent }};">{{ $phone }}</a>@endif.
    </td></tr>

    <tr><td class="m-pad" style="background:#FAF9F7;border-top:1px solid #E5E2DC;padding:18px 36px;font-family:{!! $font !!};font-size:12px;line-height:1.6;color:#68645D;">
      <strong style="color:#1B1A18;">{{ $hotel }}</strong>@if($address) · {{ $address }}@endif
      @if($phone)<br>{{ $phone }}@endif @if($email)· <a href="mailto:{{ $email }}" style="color:#68645D;">{{ $email }}</a>@endif
      <br><span style="color:#9A958C;">Reservas con HotelIgnite</span>
    </td></tr>

  </table>
</td></tr>
</table>
</body>
</html>
