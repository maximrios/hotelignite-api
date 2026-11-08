# Motor de reservas

`engine/` (Next.js) es el stepper público. Su servidor habla con
`/api/booking/v1/{slug}` usando `BOOKING_ENGINE_KEY`; el navegador nunca ve la
credencial ni llama a esta API, así que no hay CORS ni claves públicas.

## Flujo

1. `GET {slug}/search`: tipos libres con sus tarifas.
2. `POST {slug}/holds`: aparta una habitación 15 minutos, con precio calculado acá.
3. `GET {slug}/holds/{token}`: el checkout lee todo de acá. La URL lleva sólo el token.
   `DELETE {slug}/holds/{token}` lo libera al momento (el "Cambiar fechas o habitaciones" del checkout).
4. `POST {slug}/holds/{token}/confirm`: crea la `Reservation` en estado Pendiente, **sin habitación**
   (va al carril "Sin asignar" del calendario). Avisa al hotel por
   email y le manda al huésped el "Recibimos tu solicitud".
5. El hotel responde con `POST v1/reservations/{id}/confirm` o con el cancelar de siempre.
   **Por defecto no se le escribe al huésped**: el hotel le manda los datos y el link de pago desde su
   propio email. Con `notify_guest: true` (la casilla del PMS) sale el aviso automático de
   confirmación o rechazo. El vencimiento sí le avisa siempre.

## Qué bloquea y qué no

Decisión 2026-11-06: sólo bloquea lo que el hotel confirmó.

- El hold de 15 minutos sí bloquea (protege al que está cargando sus datos) y tiene topes.
- La solicitud pendiente **no** bloquea: nace con `room_id = null` y no descuenta la grilla
  (`Reservation::holdsInventory()`). Así dos solicitudes pueden pedir la misma última habitación
  sin chocar con el `EXCLUDE` de la base.
- Al confirmar, `ReservationRepository::confirmRequest` asigna una habitación libre del tipo
  (o respeta la que el hotel puso a mano en el calendario) y descuenta la grilla. Si no queda,
  responde 409 "Ya no quedan habitaciones … libres" y la solicitud sigue pendiente para rechazarla.

## Activar un alojamiento

Requiere `booking_mode = 'request'` y un plan con `booking_engine`. El modo se
activa a mano a propósito: sólo se ofrece lo que tiene tarifa en todas las
noches, `max_occupancy` cargado y habitaciones operativas.

## Datos de la solicitud

`POST holds/{token}/confirm` pide nombre, apellido, email, teléfono y `accepted_terms`. Además
acepta, todo opcional:

- `document_type` (`dni`, `passport`, `other`), `document_number` y `nationality` (ISO de 2
  letras): van al `Guest`.
- `occupancy`: `[{adults, children}]`, una entrada por habitación en el orden del hold. Tiene que
  sumar los huéspedes del hold, con un adulto por habitación y sin pasar `max_occupancy`.
- `arrival_time`, `children_ages`, `payment_plan` (`deposit`/`full`) y `payment_method`
  (`mercadopago`/`transfer`): se arman como texto en `special_requests`, antes de lo que
  escribió el huésped.
- `extras`: `[{id, quantity}]` de los que ofrece el hold, con precio. Van como líneas propias
  (`reservation_extras`), no como texto. Ver `docs/extras.md`.

## Emails

- **Al huésped** (`BookingGuestMail`, `emails/booking-guest`): con la marca del hotel (logo o
  inicial, nombre, dirección, contacto). Sale de nuestra dirección con el nombre del hotel y el
  reply-to es el email del hotel. Recibida, confirmada, rechazada y vencida.
- **Al hotel** (`BookingRequestReceivedMail`, `emails/booking-request-received`): con la marca de
  HotelIgnite, reply-to al huésped y botón al calendario del PMS con la reserva abierta. Va sólo
  al email del alojamiento; si no tiene, a los usuarios de la cuenta. Los usuarios ya lo ven en
  la campana.

## Origen de la visita

El hold acepta `attribution: {source, medium, campaign}` (los `utm_*` con los que el huésped llegó;
otras claves se rechazan). Se guarda en `booking_holds.attribution` y pasa a cada reserva
(`reservations.attribution`, en el resource y como "Origen" en la ficha del PMS). La web del
hotel recuerda los utm de la página por la que entró y los manda al motor en el enlace.

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

## Llegar desde la web del hotel

- La URL es la búsqueda. El motor la lleva a los topes del buscador (`engine/lib/stay.ts`:
  hasta 12 adultos, 8 menores, habitaciones ≤ adultos y ≤ 4) y la reescribe; la API valida
  los mismos topes (`BookingEngineRepository::MAX_ADULTS/MAX_CHILDREN`).
- `continue=1` (con `room_type`/`rate_plan`): si todo lo elegido sigue disponible y entran los
  huéspedes, el motor aparta solo y abre Extras. Se marca en `history.state`, así "atrás"
  vuelve al paso 1 sin saltar de nuevo. Si algo no da, queda en el paso 1 con el aviso.
- Volver al paso 1 con "atrás" libera el hold de esa pestaña (sessionStorage), para que el
  huésped no se bloquee la última habitación a sí mismo.

## Fotos de las habitaciones

Cada tarjeta muestra las fotos del **tipo de habitación** (`images` polimórfica,
orden por `order`; la primera es la portada). El hotelero las carga en el PMS,
en Habitaciones → tipo → Fotos (API `admin/v1/accommodations/{id}/room-types/{type}/images`,
mismo contrato que la galería del hotel). Un tipo sin fotos usa la portada del
hotel. El reorden de las dos galerías recibe `ids` en el orden final.

## Protecciones

- Turnstile en el hold si hay `TURNSTILE_SECRET_KEY`. Con el secreto cargado, engine necesita
  su site key y el hostname del motor (p. ej. `reservas.<dominio-del-hotel>`) tiene que estar
  en el widget. La web del hotel ya no aparta: sólo enlaza al motor.
- Hasta 6 habitaciones apartadas por visitante y la mitad del hotel a la vez (siempre entran 4).
- Hasta 2 solicitudes pendientes por email en cada hotel.

## Límites conocidos

- No hay sincronización con OTAs: `request` existe para que el hotel revise cada pedido.
- Dos solicitudes pueden pedir la misma última habitación: la segunda no se puede confirmar.
- El email no se verifica.
- Pago online: etapa 3, con Mercado Pago por hotel.
