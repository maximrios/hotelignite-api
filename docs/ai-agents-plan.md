# Agentes IA — HotelIgnite + Colmexa

> Escrito el 2026-11-03. Del lado de Colmexa: `~/projects/colmexa/backend/docs/api-partner.md`.

## Reparto

| | Dónde |
|---|---|
| **El agente**: tools, reglas del dominio (`instructions` del MCP), permisos, datos, qué plan lo incluye | HotelIgnite |
| **El motor**: loop del LLM, canales (WhatsApp, Telegram, widget), derivación a humano, confirmaciones, créditos, bandeja | Colmexa |

HotelIgnite es un **partner** de Colmexa. Decisiones del 2026-11-03:

- **Un tenant de Colmexa por alojamiento** (calza con el plan por alojamiento).
  `ref` = `accommodations.id`.
- **HotelIgnite paga el LLM**: los créditos vienen con el plan y HotelIgnite se
  los carga al tenant. El hotelero nunca ve Colmexa como algo aparte.

## Catálogo (`App\Agents\AgentCatalog`)

| Slug | Agente | Para | MCP | Feature | Estado |
|---|---|---|---|---|---|
| `recepcionista` | Recepcionista | Huéspedes | `/mcp/accommodation` | `ai_assistant` (Pro) | **Hecho** |
| `asistente-pms` | Asistente del equipo | Staff | `/mcp/pms` | `ai_pms_assistant` | Próximamente (`pms-assistant-plan.md`) |
| `revenue` | Revenue | Dueño/gerente | — | `ai_revenue` | Próximamente (necesita ejecuciones disparadas en Colmexa) |
| `conserje` | Conserje | Huésped con reserva | — | `ai_concierge` | Próximamente (necesita plantillas HSM) |

Un agente es código, no tabla: sin MCP no existe. Los que no tienen `mcpPath`
se muestran como "Próximamente" y la API no los deja activar (422). Las
features de los próximos **no están sembradas**: sumarlas a `PlanFeatureSeeder`
cuando exista su MCP.

## Flujo de activación (`App\Agents\AgentProvisioner`)

`PUT /api/admin/v1/accommodations/{id}/agents/{agent}` con `{ greeting, allowed_origins[] }`:

1. Chequea la feature del plan (403) y que el alojamiento tenga cuenta (422).
2. Emite una **key nueva** (`AccommodationApiKey`, nombre "Colmexa · …").
3. `PUT tenants/{id}` y `PUT tenants/{id}/agents/{agent}` en Colmexa, con la key
   partida en `client_id` (el prefijo) y `client_secret`.
4. Si Colmexa aceptó: revoca la key anterior y guarda el resultado
   (`accommodation_agents`: estado, clave pública del widget, `last_error` del
   probe). Si falló: revoca la nueva; si el agente ya estaba andando sigue andando
   con la anterior, si no queda en `error`. 503 al PMS.
5. Carga los créditos del mes (abajo).

En la primera activación (o al reactivar) invita al **usuario principal** a la
bandeja de Colmexa como owner: quien activa, si es de la cuenta; si activa
plataforma, el usuario más antiguo de la cuenta. Queda en
`settings.inbox_owner`. El resto del equipo lo suma el hotel desde la bandeja:
**no se sincroniza** (decisión de Maxi, 2026-11-03). En la bandeja el owner sólo
maneja equipo y atención; bots, canales y créditos los administra HotelIgnite, y
**el WhatsApp lo conecta Maxi** como super-admin de Colmexa.

**Cada sincronización rota la key**: el secreto en claro sólo existe durante el
request y no se guarda en ningún lado de HotelIgnite.

`DELETE …/agents/{agent}` apaga en Colmexa, revoca la key y deja `inactive`.

## Bandeja (inbox.hotelignite.com)

Lo que el agente deriva se atiende en el panel de Colmexa con la marca de
HotelIgnite (marca blanca por dominio, ver `colmexa/backend/docs/api-partner.md`).
El owner (`settings.inbox_owner`) suma al equipo desde ahí. En el PMS, `/agentes`
muestra la tarjeta "Bandeja de conversaciones" con **Abrir bandeja**:
`POST admin/v1/accommodations/{id}/inbox-link` pide a Colmexa un enlace de un
solo uso para el email del usuario logueado (403 con a quién pedírselo si no es
del equipo; 422 sin agente activo). El PMS abre la pestaña antes del `await`
(si no, el navegador la bloquea como popup).

## Créditos

Un crédito = una respuesta del agente. La cantidad es el **límite de
`ai_assistant`** del plan (500 Pro, 1000 Premium): el mismo número que
`AccommodationBillingRepository::grantMonthlyCredits()` anota en el libro de
HotelIgnite (`ai_credit_transactions`, el que muestra Facturación).

- Se cargan al activar y con `agents:grant-credits` (diario a las 03:00, zona
  `app.destination_timezone`). La referencia `plan-YYYY-MM` (mes en la zona del
  alojamiento) hace que repetir no sume.
- En Colmexa los créditos **se acumulan**: no vencen a fin de mes.

**Hay dos libros, y todavía no hablan:**

- Los **packs comprados** en HotelIgnite (`REASON_PACK_PURCHASE`) no se
  reenvían a Colmexa.
- El **consumo** ocurre en Colmexa; Facturación de HotelIgnite muestra
  `used = 0`. Falta leerlo de Colmexa (endpoint de movimientos o webhook).

## Qué le falta a la ficha (`App\Agents\AgentReadiness`)

El agente tiene la regla de no inventar, así que cada dato que falta es una
pregunta sin respuesta. `GET …/agents` devuelve `meta.readiness`: habitaciones con
capacidad, habitaciones físicas (inventario) y tarifas de los próximos 60 días
(**obligatorios**: sin eso no cotiza); descripción, servicios, políticas, cancelación, ubicación y fotos
(recomendados). Cada uno trae el link a la pantalla del PMS donde se completa
(`/hotel?tab=…` abre la pestaña).

## Lo que ve el agente de la ficha

`get_accommodation_info` (`GetAccommodationDetailsService`) devuelve, además de lo
que ya tenía: `stars`, `location` (referencia, CP, link de Google Maps desde la
columna `location` de PostGIS), por habitación `size_m2`, `max_adults`,
`max_children`, `beds`, `services` y descripción; política de fiestas;
`cancellation` por tipo de tarifa (`accommodation_rate_policies`; desde el
2026-11-03 los tipos son los mismos que `rate_plans.cancellation`: flexible,
moderate, strict, non_refundable — `AccommodationRatePolicy::TYPES`; el PMS los
edita en Políticas → Reglas de tarifa vía `GET/PUT admin/v1/accommodations/{id}/policies`); y `nearby`
(hasta 3 lugares por categoría, con distancia a pie o en km).

**No existe en la base** y el agente no lo puede contestar: detalle de mascotas
(tamaño, costo), estacionamiento (techado, costo), horario del desayuno, cómo
llegar desde la terminal o el aeropuerto, idiomas del personal, accesibilidad,
seña o depósito. Propuesta: una sección de **Preguntas frecuentes** en la ficha
(pregunta + respuesta, multilenguaje), expuesta por el MCP.

## Configuración

```dotenv
COLMEXA_URL=http://messaging_nginx          # cómo llega la API de HotelIgnite a Colmexa
COLMEXA_PARTNER_TOKEN=…                     # php artisan partner:create hotelignite "HotelIgnite" (en Colmexa)
COLMEXA_MCP_BASE_URL=http://nginx           # cómo llega Colmexa al MCP de HotelIgnite (red docker)
```

Sin `COLMEXA_URL` o sin token, la página muestra el catálogo y la ficha, pero no
deja activar (`meta.service_available = false`).

## Para desplegar

1. Colmexa: `migrate` (tabla `partners`, columnas en `tenants` y
   `credit_movements`) y `partner:create hotelignite "HotelIgnite"`.
2. HotelIgnite: `migrate` (`accommodation_agents`), las tres variables de arriba
   en el `.env` del VPS, `optimize`, reiniciar `queue` y el scheduler.
3. Los dos contenedores de Colmexa que corren el agente (`messaging_app` y
   `messaging_worker`) tienen que estar en la red `hotelignite`.

## Cupo: lo mismo que el motor de reservas

`App\Support\RoomInventory` es la única definición de "hay lugar", y la usan el
motor (`BookingEngineRepository`) y `check_availability`
(`CheckAvailabilityService`): una **habitación física** (`rooms`) libre toda la
estadía, sin reserva ni retención vigente y sin estar fuera de servicio, en un
tipo que el hotel **no cerró** en `room_availability`. Esa tabla es la grilla de
cierres, no el inventario: vacía = abierto.

Hasta el 2026-11-03 el agente exigía una fila por noche en `room_availability` y,
como nadie la carga, contestaba `unknown` (y la ficha pedía "Disponibilidad de
los próximos 60 días") aunque el hotel tuviera habitaciones y tarifas. Los tipos
sin habitaciones cargadas siguen con la lógica vieja (filas por noche o mock).
