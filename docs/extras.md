# Extras

Lo que el hotel vende además de la habitación (desayuno, cochera, traslado, cuna…).
Diseño acordado con el agente `hoteleria` el 2026-11-08.

## Dónde se cargan

En el PMS, **Tarifas → Extras** (`api/v1/extras`, `ExtraRepository`). Cada hotel arma los
suyos; no hay catálogo en el CRM. Para no arrancar de cero, el PMS ofrece las plantillas de
`Extra::TEMPLATES` (desayuno, cochera, late check-out, traslado, cuna): el hotel pone el
precio y la activa. Una plantilla usada deja de sugerirse. No se mezclan con `services`
(amenities informativos, sin precio).

Campos: nombre, descripción, precio (centavos en la base, pesos en la API), modalidad
(`stay` por estadía, `night` por noche, `person` por persona, `person_night` por persona y
noche), máximo por reserva (1–10), precio con IVA o más IVA, si lo confirma el hotel y
activo/pausado, y una **foto** opcional (`image_url`, subida a Cloudinary desde el formulario;
en el motor reemplaza al ícono). La baja es lógica (`SoftDeletes`): las reservas que lo pidieron lo conservan.

## En el motor

El hold (`GET holds/{token}`) trae `extras`: los activos, con `units` (noches, personas o
personas × noches de esa estadía) y `total` por unidad elegida. El desayuno
(`template = breakfast`) sale `included` si alguna tarifa del hold trae desayuno
(`RatePlan::includesBreakfast()`): se muestra y no se cobra.

`POST holds/{token}/confirm` recibe `extras: [{id, quantity}]`. La API recalcula el precio
(nunca lo toma del motor) y rechaza con 422 un extra ajeno, pausado, borrado, incluido o
por encima del máximo.

## En la reserva

`reservation_extras`: una línea por extra con **foto** del nombre, modalidad, precio
unitario, unidades, cantidad y total. Si el hotel cambia el extra después, la reserva no se
mueve. En una solicitud de varias habitaciones cuelgan de la primera del grupo.
`reservations.total_amount` sigue siendo sólo la habitación: los extras van aparte (para
reportes e ingresos por extras).

El detalle del PMS (`GET reservations/{id}`) trae `extras`; el slide-out los muestra con
"Total con extras" y deja quitar una línea (`DELETE reservations/{id}/extras/{line}`) salvo
en reservas canceladas, no-show o con check-out. Los mails del motor (huésped y hotel)
listan los extras y suman el total.

## En el MCP

`get_accommodation_info` trae `extras` (los activos): nombre, descripción, precio, moneda,
modalidad legible ("por noche"), si depende de disponibilidad, máximo por reserva, foto y
la nota del desayuno incluido. El agente los informa; se piden al reservar en el motor.

## Extras y servicios no son lo mismo

`services` (catálogo global, amenities: wifi, pileta, aire) dice lo que el lugar **tiene**,
incluido en el precio y sin precio propio. Un extra es algo que se **paga aparte** y se
agrega a la reserva. Puede haber un servicio "Estacionamiento" (hay cochera) y un extra
"Cochera" ($6.000 por noche): el primero informa, el segundo se vende.

## Pendiente

Temporadas, stock por día (cunas), restricción por tipo o plan, ajustar precio o
cantidad de una línea (hoy sólo quitar), cargar extras en una reserva hecha desde el PMS.
