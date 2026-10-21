# Plan — MCP del alojamiento (respuestas automáticas a huéspedes)

> Lado HotelIgnite de las respuestas automáticas: que un alojamiento conecte un
> agente externo (Colmexa por WhatsApp/Telegram/web, u otro cliente MCP) y que ese
> agente conteste con los datos reales del hotel y deje consultas en el PMS.
> Fecha: 2026-10-20 · Stack: Laravel 13 · `laravel/mcp` v1.
> Relacionado: `docs/mcp-traveler-plan.md` (el MCP que ya existe),
> `docs/mcp-design.md`, `docs/experiences-plan.md` (inquiries polimórficas).

## Estado (2026-10-20)

- **Fase 1: hecha en dev, sin commit ni deploy.** Migración
  `2026_10_20_000003_create_accommodation_api_keys_table`, modelo
  `AccommodationApiKey`, middlewares `auth.accommodation` y `accommodation.ability`
  (gemelo de `client.ability`, no se generalizó), rate limiter `accommodation`,
  feature `ai_assistant` en Starter/Pro/Enterprise y endpoints en
  `admin/v1/accommodations/{accommodation}/api-keys` (el PMS consume `admin/v1`).
  Tests: `tests/Feature/AccommodationApiKeyTest.php` (16).
- Para prod: `migrate` + re-correr `FeatureSeeder` y `PlanFeatureSeeder` (los corre Maxi) + `PMS_URL` en el `.env` del VPS (sin eso el link del mail apunta al host de dev).
- **Fase 2: hecha en dev, sin commit ni deploy.** `POST /mcp/accommodation`
  (`AccommodationServer`), herramientas en `App\Mcp\Tools\Accommodation`:
  `get_accommodation_info` (sin argumentos: la ficha es compacta, no hizo falta
  `topic`) y `check_availability`. `AiContext` suma `accommodation` (y `client`
  pasa a nullable); `GetAccommodationDetailsService::handleCurrent()`. Tests:
  `tests/Feature/McpAccommodationTest.php` (12).
- Hallazgo: `laravel/mcp` publica una herramienta sin argumentos con
  `"properties": []` (array JSON), que los clientes estrictos rechazan.
  `AccommodationTool::toArray()` lo corrige a `{}`.
- **Fase 3: hecha en dev, sin commit ni deploy.** Migración
  `2026_10_20_000004_add_source_to_inquiries` (`source` = `web|portal|agent`,
  `lastname`/`email` nullable). Herramienta `create_inquiry` (exige la ability
  `inquiry:create` dentro de la herramienta: la ruta sólo pide `mcp:read`),
  `InquiryRepository::storeFromAgent()` con dedupe de 30 min por teléfono +
  llegada, y `InquiryReceivedMail` para **toda** consulta nueva (alojamiento →
  usuarios de la cuenta que lo ven; experiencia → usuarios del client). Config
  nueva `PMS_URL` para el link del mail. PMS `/consultas`: muestra el origen y un
  link "Responder por WhatsApp". Se descartó `source_ref`: nadie lo iba a llenar.
- Decisión tomada al implementar: **el client no ve las consultas del asistente**
  aunque el alojamiento esté en su padrón (`Inquiry::scopeVisibleTo`). Son leads
  del hotel por su propio canal.
- Para Colmexa: el modelo sólo conoce el teléfono del huésped si Colmexa se lo
  pasa (el `wa_id` en el system prompt). Sin eso, `create_inquiry` se lo pide.
- Pendiente de la fase 2: la ficha no trae teléfono ni email del alojamiento (el
  servicio es el mismo que el del portal, que los oculta a propósito). Decidir si
  el agente del propio hotel los puede dar.
- El actor que deja el middleware ve **toda la cuenta** (`visibleTo`); las
  herramientas se acotan a `AiContext::$accommodation` y no reciben ids del modelo.
- **Colmexa conectado en dev (2026-10-20).** El middleware acepta la key también como
  `ak_live_<prefix>.<secret>` (es como Colmexa arma el Bearer con `client_id.client_secret`)
  y el alta devuelve `client_id`/`client_secret`. Del lado de Colmexa se hizo la fase 3 de
  su plan: el agente atiende web, Telegram y WhatsApp. Cómo probarlo:
  [`probar-asistente.md`](probar-asistente.md).

## Reparto de responsabilidades

```
Huésped ─▶ WhatsApp / Telegram / widget ─▶ Colmexa (canal, bandeja, agente LLM, handoff)
                                                │  cliente MCP, credencial del alojamiento
                                                ▼
                                   HotelIgnite  POST /mcp/accommodation
                                   (datos, reglas, tenencia, consultas)
```

- **HotelIgnite** es la fuente de verdad: habitaciones, tarifas, disponibilidad,
  políticas, consultas. Decide qué ve y qué escribe cada credencial.
- **Colmexa** no guarda datos del hotel. Es un consumidor más del MCP, igual que
  podría serlo el chat de la web del hotel o Claude directamente.
- Fuera de este plan (lado Colmexa): que un canal mande **todo** contacto entrante
  al agente, no sólo los `ConnectorContact` habilitados.

## Lo que ya hay y lo que falta

| Pieza | Hoy | Falta |
|---|---|---|
| Servidor MCP | `/mcp/traveler`, 5 herramientas de lectura (`App\Mcp\Tools`) | Un servidor para **un** alojamiento |
| Credencial | `ClientApiKey` → actor `client`, ve su padrón | Credencial de **cuenta hotelera**, atada a un alojamiento |
| Tenencia en `App\AI` | Los servicios sólo usan `Accommodation::visibleTo($ctx->actor)` | Nada: un actor `account` ve lo suyo sin cambios |
| Consultas | `Inquiry` polimórfica; alta por `client/v1` exige email | Alta desde el agente, con email opcional y origen |
| Aviso al hotelero | No hay (pendiente de experiencias) | Mail al entrar una consulta |
| Datos reales | En dev y prod casi no hay room types, inventario ni tarifas | Un hotel piloto con todo cargado |

## Decisiones propuestas

1. **Endpoint propio `/mcp/accommodation`**, no reutilizar `/mcp/traveler`. Las
   instrucciones del servidor son otras ("sos el asistente de *este* alojamiento") y
   las herramientas no reciben `accommodation_id`: el modelo no puede equivocarse
   de hotel ni preguntar por otro de la misma cuenta. Los servicios de `App\AI` se
   reutilizan tal cual.
2. **Credencial nueva `AccommodationApiKey`**, no generalizar `ClientApiKey`. Mismo
   formato y mismo esquema de hash, prefijo `ak_live_` para distinguirlas en logs y
   en el middleware. Pertenece a un `account_id` y a **un** `accommodation_id`
   (obligatorio en el MVP; una cadena genera una key por alojamiento).
3. **Abilities:** `mcp:read` y `inquiry:create`. `booking:create` queda para la
   fase 4.
4. **La consulta del agente no exige email.** Por WhatsApp se tiene el teléfono y el
   nombre de perfil; pedirle el mail al huésped corta la conversación.
5. **El origen queda guardado**: columna `source` (`web` | `portal` | `agent`) y
   `source_ref` (id de conversación del agente, opcional). No se usa `channel_id`:
   esa FK apunta a los canales de venta (OTAs), otra cosa.

## Fases

### Fase 1 — Credencial del alojamiento

- Migración `accommodation_api_keys`: `id`, `account_id` (FK), `accommodation_id`
  (FK), `name`, `prefix` (único), `key_hash`, `abilities` (json), `last_used_at`,
  `expires_at`, `revoked_at`, timestamps.
- Modelo `AccommodationApiKey` con `generateFor(Accommodation, name, abilities)`,
  `isActive()`, `can()`; mismo patrón que `ClientApiKey`.
- Middleware `auth.accommodation` (`AuthenticateAccommodationKey`): valida la key,
  que la cuenta esté activa y que el alojamiento siga perteneciendo a la cuenta y
  esté `enabled`. Arma un `User` en memoria con `user_type=account` y el
  `account_id`, y deja `api_accommodation` y `api_key` en la request.
- Middleware de ability: generalizar `client.ability` para que lea `api_key` sin
  importar el tipo, o uno gemelo `accommodation.ability`.
- Rate limiter `accommodation` por `accommodation_id` (límite fijo en el MVP; por
  plan cuando se cobre IA).
- Feature nueva `ai_assistant` (módulo `platform`, `boolean`) en `FeatureSeeder` y
  `PlanFeatureSeeder`. El middleware rechaza la key si
  `! $accommodation->hasFeature('ai_assistant')`: el plan es por alojamiento, así
  que bajar de plan apaga el asistente sin revocar nada.
- PMS: endpoints `GET/POST/DELETE /api/admin/v1/accommodations/{id}/api-keys` (listar,
  crear mostrando el secreto **una vez**, revocar), con la policy del alojamiento.

### Fase 2 — Servidor MCP del alojamiento, lectura

- `AiContext` suma `?Accommodation $accommodation` y `?Client $client` pasa a
  nullable. Nuevo `AiContext::fromAccommodationRequest()`.
- `App\Mcp\Servers\AccommodationServer` en `routes/ai.php`:
  `Mcp::web('/mcp/accommodation', …)->middleware(['auth.accommodation', 'throttle:accommodation', '…ability:mcp:read'])`.
- Herramientas (adapters finos sobre los servicios existentes, sin id de
  alojamiento en los argumentos):

| Herramienta | Servicio | Argumentos |
|---|---|---|
| `get_accommodation_info` | `GetAccommodationDetailsService` | `topic?` (habitaciones, servicios, políticas, ubicación) |
| `check_availability` | `CheckAvailabilityService` | `checkin`, `checkout`, `adults`, `children` |

- Instrucciones del servidor: contestar sólo sobre este alojamiento; precios y
  disponibilidad sólo desde herramientas; `unknown` = "lo consulto con el
  alojamiento" y ofrecer dejar la consulta; nunca inventar políticas.
- Revisar `GetAccommodationDetailsService`: hoy no devuelve contacto del prestador
  (correcto para el portal), pero acá sí sirven dirección, cómo llegar y horarios de
  check-in/out.

### Fase 3 — Dejar una consulta

- Migración en `inquiries`: `email` y `lastname` nullable; `source` (string,
  default `web`) y `source_ref` (nullable).
- `InquiryRepository::storeFromAgent(Accommodation, array $data, ?string $sourceRef)`.
- Herramienta `create_inquiry`: `name`, `phone`, `email?`, `checkin`, `checkout?`,
  `adults`, `children`, `message?`. Ability `inquiry:create`.
- **Idempotencia:** si en los últimos 30 minutos ya hay una consulta con mismo
  alojamiento + teléfono + `checkin`, se devuelve esa en lugar de duplicar (el
  agente puede reintentar una tool call).
- La descripción de la herramienta le pide al modelo confirmar los datos con el
  huésped antes de llamarla. Colmexa además la pasa por su `ConfirmationGate`.
- **Aviso al hotelero:** mail (`InquiryReceived`, encolado) a los usuarios de la
  cuenta. Sirve también para las consultas de portales y experiencias, que lo tienen
  pendiente.
- PMS `/consultas`: mostrar el origen ("WhatsApp vía asistente") y el teléfono con
  link a `wa.me`.

### Fase 4 — Pre-reserva (después)

- Herramienta `create_booking` sobre la lógica de `client/v1/bookings`, con ability
  `booking:create`. Requiere tarifas reales (sin `price_from` real no se reserva) y
  definir pago o seña. Se planifica aparte cuando haya un hotel con inventario real.

### Fase 5 — Activación desde el PMS

- Pantalla "Asistente" en el PMS: generar la key, copiar URL del MCP + key, y un
  checklist de lo que el agente necesita para contestar bien (room types con
  ocupación, inventario, tarifas, políticas). Hoy casi ningún alojamiento lo
  tiene: es lo que más va a limitar la calidad de las respuestas.

## Tests (`tests/Feature/McpAccommodationTest.php`)

- Key válida → ve **sólo** su alojamiento; otro alojamiento de la misma cuenta, no.
- Key revocada / expirada → 401. Alojamiento transferido de cuenta o deshabilitado → 401.
- Key sin `inquiry:create` → `create_inquiry` falla; con ella, crea con
  `source=agent`, sin email.
- Idempotencia: dos llamadas iguales → una sola consulta.
- Alojamiento con un plan sin `ai_assistant` → 403.
- La consulta aparece en `/api/v1/inquiries` del hotelero y dispara el mail.
- Una `ClientApiKey` no entra a `/mcp/accommodation` y una `AccommodationApiKey` no
  entra a `/mcp/traveler` ni a `client/v1`.

## Antes de arrancar

1. **Elegir el hotel piloto** y cargarle room types con `max_occupancy`,
   inventario, tarifas y políticas. Sin eso, en producción todo sale `unknown` y sin
   precio (mocks apagados, ver `docs/mcp-mocks-todo.md` M1–M4).
2. Confirmar con Colmexa cómo manda la credencial a un conector MCP
   (`Authorization: Bearer`, como hoy en `/mcp/traveler`).

## Decisiones (Maxi, 2026-10-20)

1. **Key por alojamiento.** Una cadena genera una key por cada uno.
2. **`ai_assistant` desde Starter** (los tiers son acumulativos: Starter, Pro y
   Enterprise). Free no la tiene.
3. **Leyenda de datos personales (Ley 25.326)** en los canales donde escribe el
   huésped (WhatsApp, widget en la web del hotel) y en los términos del PMS.

## Fuera de este plan: el asistente del hotelero

El asistente que use el hotelero **dentro del PMS** es otro caso (Grupo B de
`docs/mcp-design.md`): entra con su sesión Sanctum, actúa como él y sobre sus
alojamientos. No usa `AccommodationApiKey`, no pide datos de contacto y no
necesita la leyenda. Se planifica aparte.
