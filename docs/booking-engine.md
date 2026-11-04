# Motor de reservas

`engine/` (Next.js) es el stepper público. Su servidor habla con
`/api/booking/v1/{slug}` usando `BOOKING_ENGINE_KEY`; el navegador nunca ve la
credencial ni llama a esta API, así que no hay CORS ni claves públicas.

## Flujo

1. `GET {slug}/search`: tipos libres con sus tarifas.
2. `POST {slug}/holds`: aparta una habitación 15 minutos, con precio calculado acá.
3. `GET {slug}/holds/{token}`: el checkout lee todo de acá. La URL lleva sólo el token.
4. `POST {slug}/holds/{token}/confirm`: crea la `Reservation` en estado Pendiente.
5. El hotel responde con `POST v1/reservations/{id}/confirm` o con el cancelar de siempre.

## Activar un alojamiento

Requiere `booking_mode = 'request'` y un plan con `booking_engine`. El modo se
activa a mano a propósito: sólo se ofrece lo que tiene tarifa en todas las
noches, `max_occupancy` cargado y habitaciones operativas.

## Varias habitaciones

Una solicitud lleva hasta 4 habitaciones (`items` en el hold). Se aparta todo o nada y
los huéspedes se reparten con un adulto por habitación, llenando primero las más grandes.
Cada habitación es una `Reservation` con su código, unidas por `booking_group_id`. El
hotel confirma o rechaza el grupo entero. Un grupo ya confirmado se cancela de a una.

## Vencimiento

Una solicitud sin responder vence: `booking:expire-requests` corre cada minuto, cancela
el grupo y avisa al huésped y al hotel. Se configura a mano por alojamiento:

- `booking_request_minutes`: 60 por defecto, entre 30 y 720.
- `booking_attention_from` / `booking_attention_to`: horario de atención opcional, en hora
  local. El reloj sólo corre dentro de esa ventana.

Nunca pasa del fin del día de llegada. Sin scheduler no vence nada.

## Protecciones

- Turnstile en el hold si hay `TURNSTILE_SECRET_KEY`. Con el secreto cargado, el sitio y
  engine necesitan su site key, y el hostname de cada hotel tiene que estar en el widget.
- Hasta 6 habitaciones apartadas por visitante y la mitad del hotel a la vez (siempre entran 4).
- Hasta 2 solicitudes pendientes por email en cada hotel.

## Límites conocidos

- No hay sincronización con OTAs: `request` existe para que el hotel revise cada pedido.
- Mientras una solicitud no vence ni se responde, ocupa sus habitaciones.
- El email no se verifica.
- Pago online: etapa 3, con Mercado Pago por hotel.
