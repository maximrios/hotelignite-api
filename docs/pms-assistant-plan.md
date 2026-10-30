# Plan — Asistente del hotelero en el PMS (vía Colmexa)

> Fecha: 2026-10-28 · Nada implementado todavía.
> Grupo B de `docs/mcp-design.md`. Es el caso que quedó "fuera de plan" en
> `docs/accommodation-mcp-plan.md`.
> Del lado de Colmexa: `~/projects/colmexa/backend/docs/plan-contexto-y-credencial-delegada.md`.

Un asistente dentro del PMS que **actúa como el usuario logueado**: ve sus
alojamientos, consulta reservas y tarifas y, con confirmación, cambia tarifas y
cupos. El motor del agente (LLM, loop, confirmaciones, créditos) es Colmexa. La
key del LLM vive solamente en Colmexa. HotelIgnite pone los datos, las reglas y
los permisos, con un servidor MCP propio.

## Decisión de auth: credencial delegada (opción 1)

El MCP del PMS no usa una key de máquina. Usa un **token Sanctum del usuario**
emitido para el asistente, así las Policies y `visibleTo($user)` deciden igual
que en el PMS. Dos usuarios de la misma cuenta pueden ver y hacer cosas
distintas en el asistente.

## Flujo

```
PMS (Next)            HotelIgnite API                          Colmexa
    │ 1. POST /admin/v1/assistant/session (Bearer del usuario)
    ├──────────────────────▶│ 2. token delegado (Sanctum, 8 h, abilities mcp:pms:*)
    │                       │ 3. POST /api/v1/channels/{pms}/embed-sessions
    │                       ├─────────────────────────────────────────▶│ guarda contacto, contexto y token
    │                       │◀───────────────── { session_token } ─────┤
    │◀── { session_token } ─┤
    │ 4. widget.js con session_token (sin pre-chat); setContext() al cambiar de ruta
    ├──────────────────────────────────────────────────────────────────▶│
    │                       │◀── 5. tools/call /mcp/pms, Bearer delegado
    │                       │    (auth:sanctum + Policies del usuario)
```

El token delegado no pasa por el navegador, y el token de HotelIgnite en Colmexa
tampoco.

---

## Fase 1 — Token delegado y sesión

### `POST /api/admin/v1/assistant/session`

- `auth:sanctum`. Solo usuarios de cuenta hotelera (no `client`, no `platform` en
  el MVP), con la feature `ai_assistant` en el plan de la cuenta (Starter o más).
- Revoca los tokens `assistant` anteriores del usuario y emite uno nuevo:
  `createToken('assistant', $abilities, now()->addHours(8))`.
  - `mcp:pms:read` siempre.
  - `mcp:pms:write` solo si el rol del usuario puede editar tarifas o inventario
    (lo mismo que miran las Policies). Defensa en profundidad: la tool igual pasa
    por la Policy.
- Llama a Colmexa (`App\Services\Colmexa\EmbedSessions`) con:
  - `contact`: `user:{id}`, nombre, email.
  - `context`: rol, cuenta, alojamientos visibles (nombre + id, máx. 20) y plan.
    Es el contexto previo y le ahorra al modelo la vuelta de "¿de qué hotel?".
  - `credentials`: `[{ connector: "pms", token, expires_at }]`.
- Devuelve `{ session_token, expires_at, widget_url, widget_key }`.
- Config nueva: `COLMEXA_URL`, `COLMEXA_TOKEN` (token del tenant HotelIgnite en
  Colmexa), `COLMEXA_PMS_CHANNEL`. Si Colmexa no responde → 503 y el PMS oculta el
  botón; no rompe nada más.

### El token `assistant` no sirve fuera del MCP

Hoy las rutas de `admin/v1` y `v1` **no miran abilities** (login emite `*` o
`read` y autorizan las Policies). Un token `assistant` filtrado serviría para
toda la API REST como ese usuario. Hay que agregar:

- Middleware `deny.assistant-token` en el grupo `api`: rechaza (403) cualquier
  token con `name = 'assistant'` fuera de `/mcp/pms`.
- Registrar los alias `abilities` / `ability` de Sanctum (`CheckAbilities`,
  `CheckForAnyAbility`) en `bootstrap/app.php`: hoy ninguna ruta los usa y no
  están dados de alta (verificado el 2026-10-28).
- Test que lo fije contra una ruta de escritura de `admin/v1`.

### Logout

`POST /auth/logout` también revoca los tokens `assistant` y llama a
`DELETE …/embed-sessions/user:{id}` en Colmexa (sin bloquear el logout si falla).

## Fase 2 — `/mcp/pms`, lectura

- `Mcp::web('/mcp/pms', PmsServer::class)->middleware(['auth:sanctum', 'abilities:mcp:pms:read', 'throttle:assistant'])`.
- `AiContext::fromUserRequest()`: `actor` = el usuario, `client` y
  `accommodation` null. Los servicios de `App\AI` ya filtran por
  `visibleTo($ctx->actor)`.
- **Requisito de Colmexa**: `tools/list` devuelve siempre las mismas tools, sin
  importar el usuario (allá se cachean por conector). Lo que el usuario no puede
  hacer se rechaza en la llamada.
- Tools (todas `#[IsReadOnly]`):

| Tool | Reusa | Notas |
|---|---|---|
| `list_my_accommodations` | `visibleTo` | id, nombre, ciudad, estado de publicación |
| `get_accommodation` | `GetAccommodationDetailsService` | con `accommodation_id`, validado contra `visibleTo` |
| `get_rates_and_availability` | rate plans + `room-availability` | por tipo de habitación y **rango** de fechas, no día por día |
| `list_reservations` | `GET /v1/reservations` | filtros de fecha y estado; hoy sin detalle (ver bloqueantes) |
| `list_inquiries` | `Inquiry::scopeVisibleTo` | las consultas pendientes, incluidas las del asistente del huésped |

Las instrucciones del servidor: "Sos el asistente del equipo del alojamiento… los
ids salen de las tools o del contexto; si el contexto dice que mira un tipo de
habitación, usalo como punto de partida".

## Fase 3 — Escritura, con confirmación

- Tools sin `IsReadOnly` y con `_meta.requires_confirmation = true`: Colmexa le
  pregunta "sí/no" al usuario antes de ejecutar (`ConfirmationGate`).
- Dentro de la tool: `tokenCan('mcp:pms:write')` + la Policy del recurso.
- Empezar por lo que más se usa y es reversible:
  1. `set_availability` — abrir o cerrar fechas o cupos por tipo de habitación y rango.
  2. `set_rates` — precio por rango y plan tarifario.
- Respuesta: el rango afectado con el estado antes y después, para que el agente
  confirme qué cambió.
- **Auditoría**: cada escritura deja `source = 'assistant'` en el log de
  actividad, con el usuario. Si no hay log de actividad para tarifas, este es el
  momento de agregarlo.

## Fase 4 — PMS (front)

- Botón "Asistente" en el layout (solo con la feature `ai_assistant`).
- Al abrir: `POST /admin/v1/assistant/session`, carga `widget.js` y
  `window.Colmexa.startWith(session_token)`.
- En cada cambio de ruta: `window.Colmexa.setContext({ Pantalla, Alojamiento, "Tipo de habitación", Fechas })`
  con lo que tenga la pantalla. Es el contexto dinámico; no reemplaza permisos.
- Al vencer la sesión (401 del widget) se vuelve a pedir en silencio.

---

## Bloqueantes conocidos (de `mcp-design.md`)

- `rooms` / `room_types` sin timestamps en la base de dev rompen POST/PUT:
  resolver antes de exponer `manage_room_inventory` (no está en este MVP).
- `reservations` solo tiene `index`: `list_reservations` sale con eso; detalle y
  cambio de estado requieren ampliar la API.

## Tests (`tests/Feature/McpPmsTest.php`, `AssistantSessionTest.php`)

- La sesión emite un token `assistant` con vencimiento y abilities según rol;
  revoca el anterior; sin `ai_assistant` → 403. Colmexa con `Http::fake`.
- Un token `assistant` → 403 en `admin/v1`; uno de login → 403 en `/mcp/pms`.
- Las tools no ven alojamientos de otra cuenta, aunque el modelo mande el id.
- `set_rates` sin `mcp:pms:write` → error de tool; con write pero sin Policy →
  error de tool; con las dos → cambia y audita.
- Logout revoca los tokens `assistant`.

## Decisiones abiertas

1. **Atención humana**: en el PMS, ¿pasar la conversación a soporte de
   HotelIgnite (bandeja de Colmexa) o sin atención humana? Propuesta: sin atención
   humana en el MVP (`settings.handoff = false` en el canal).
2. **Platform/staff**: ¿pueden usar el asistente sobre cualquier cuenta? Propuesta: no en el MVP.
3. **Costo por cuenta**: el saldo es del tenant HotelIgnite en Colmexa. Si se
   quiere cobrar por hotel, Colmexa tiene que agrupar consumo por
   `context.host.account_id` (anotado en su plan, paso 4).
4. **TTL** de 8 h: alcanza para un turno de recepción; ¿más corto?
5. **Datos personales**: el LLM ve nombres de huéspedes de las reservas. Leyenda
   de la Ley 25.326 en los términos del PMS (ya decidido para el asistente del
   huésped; confirmar que cubre este caso).

## Orden

Colmexa pasos 2 y 3 → Fase 1 → Fase 2 → probar de punta a punta con un usuario de
recepción y uno de solo lectura → Fase 3 → Fase 4 en paralelo desde la Fase 2.
