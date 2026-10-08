# Plan — MCP del viajero (MVP de solo lectura) + chat en turinorte (cross-repo)

> Primer corte implementable de `docs/mcp-design.md` (Grupo A): un servidor MCP en
> la API con herramientas de solo lectura para buscar alojamientos y consultar
> disponibilidad, y un chat en turinorte que lo consume.
> Fecha: 2026-10-01 · Stack: Laravel 13 · Next 16.
> Relacionado: `docs/mcp-design.md`, `docs/api-clients-plan.md`,
> `docs/travelers-auth-plan.md` (queda para después; este MVP es anónimo).

## Estado (2026-10-01)

- **api: implementado.** `App\AI` (5 servicios, `AiContext`, `RoomTypeResolver`,
  `MockInventory`, `AccommodationMapper`), servidor `App\Mcp\Servers\TravelerServer`
  con `laravel/mcp` v1 en `POST /mcp/traveler`, ability `mcp:read`, config
  `config/ai.php`. Tests: `tests/Feature/McpTravelerTest.php` (14).
- Diferencias con lo planeado: los servicios reciben argumentos tipados en lugar de
  Input DTOs (la validación de forma está en cada Tool; la de rango de fechas, en
  `CheckAvailabilityService::parseStay`), y devuelven arrays en lugar de Output
  DTOs. Se agregó `reason` (`capacity` | `no_availability` | `no_data`) a la
  disponibilidad, y `occupancy_source` puede ser `unknown`.
- `accommodation_types` no tiene `slug`: el filtro `type` es texto parcial sobre el
  nombre. (Ojo: `client/v1/accommodations?type=` filtra por `slug` y hoy falla
  contra este schema.)
- Los mensajes de validación salen en inglés (la app no tiene `lang/es`).
- **turinorte: en curso** (chat + agente portable).

## Context

El objetivo es que cada agencia (client) ofrezca en su portal un asistente que, por
chat, busque alojamientos y disponibilidad. Este MVP es para **aprender cómo se
comporta un agente con MCP** sobre datos reales, sin login ni reservas.

- **Identidad:** API key del client (la misma tenencia que `client/v1`). Resuelve el
  punto abierto de `mcp-design.md` ("token Sanctum vs API key") **para el asistente
  del viajero**: API key. El asistente del hotelero (Grupo B) sigue con Sanctum.
- **Límite de uso:** `throttle:client` (por `client_id`), el que ya existe.
- **Sin escritura:** nada de bookings ni inquiries en este corte.

## Hallazgos de la revisión

Lo que hay hoy y lo que el agente va a necesitar:

| # | Hallazgo | Impacto en el agente |
|---|---|---|
| 1 | `AccommodationAvailabilityController::check` considera **disponible** si no hay filas en `room_availabilities` para esa noche | Con datos incompletos el agente afirmaría disponibilidad que nadie confirmó. Es el riesgo principal |
| 2 | La disponibilidad **no mira la ocupación** (`adults` vs `room_types.max_occupancy`) | "Hay lugar" para 6 personas en un hotel de dobles |
| 3 | **No hay precios** en `client/v1`. Existen `rate_plans` → `rates` (precio por fecha, `currency`, `min_stay`) pero no se exponen | "¿Cuánto sale?" es la segunda pregunta de cualquier viajero |
| 4 | No hay búsqueda por destino + fechas: la disponibilidad es **por alojamiento**, con 2 queries por noche | Para "¿qué hay en Cafayate del 10 al 15?" el agente tendría que llamar N veces a `check_availability` |
| 5 | `client/v1/accommodations` filtra ciudad por **id** y no tiene **búsqueda de texto** | El agente recibe "Cafayate" o "algo con pileta", no ids |
| 6 | `PublicAccommodationResource::publicDescription()` hace 1–2 queries por ítem | N+1 en cada búsqueda del agente |
| 7 | `mcp-design.md` está desactualizado: fechas en `d/m/Y` (hoy es `Y-m-d`) y endpoints `/v1` en vez de `client/v1` | Corregir al implementar |
| 8 | La capa `App\AI` de `mcp-design.md` no existe; `laravel/mcp` no está instalado | Se construye en este plan, **solo el recorte de 5 herramientas** |

**Sin verificar** (el contenedor `php_api` estaba detenido): cuántos alojamientos
visibles para TuriNorte tienen `room_availabilities` y `rates` cargados. Si casi
ninguno los tiene, el hallazgo 1 domina todo y conviene que el agente hable de
"consultar" y no de "disponible".

## Herramientas del MVP

Todas scopeadas con `Accommodation::visibleTo($ctx->user)` y `enabled = 1`.
Respuestas **compactas** (el modelo paga por cada token que lee) y en español.

### 1. `list_destinations`
Resuelve nombres a ciudades. Input: `search?`. Output: `[{ slug, name, state,
accommodations_count }]` (solo ciudades con alojamientos visibles para el client).
Sale de `CatalogController::cities` + conteo por tenencia.

### 2. `search_accommodations`
Input: `destination?` (slug), `type?` (slug), `query?` (texto libre sobre nombre,
servicios y descripción), `limit` (máx. 10).
Output: `[{ id, slug, name, type, city, services_top[5], allow_bookings,
summary(≤200 chars) }]`. Mapper propio, **no** `PublicAccommodationResource`
(hallazgo 6).
Nuevo respecto a `client/v1`: filtro por slug de ciudad y búsqueda de texto.

### 3. `get_accommodation_details`
Input: `slug`, `language='es'`. Output: descripción, servicios, habitaciones (tipo,
`max_occupancy`), políticas (check-in/out, cancelación, mascotas) y hasta 3
imágenes. Sin email/teléfono del prestador (la consulta va por el portal).

### 4. `check_availability`
Input: `accommodation_id`, `checkin`, `checkout` (`Y-m-d`), `adults`,
`children=0`. Reutiliza la lógica actual movida a un service, con dos cambios:
- **Estado en tres valores**, no booleano: `available` (hay filas abiertas todas
  las noches), `unavailable` (alguna noche cerrada) y **`unknown`** (sin datos de
  inventario). La descripción de la herramienta le indica al modelo que `unknown`
  se comunica como "hay que consultarle al alojamiento". Corrige el hallazgo 1.
- **Filtra por capacidad**: solo cuentan los room types con
  `max_occupancy >= adults + children`. Corrige el hallazgo 2.
Output: `{ status, nights, room_types: [{ name, max_occupancy }] }`. El
`availability_token` **no** se expone al modelo en este corte (no hay reserva).

### 5. `search_availability`
Destino + fechas + huéspedes → alojamientos con lugar, en una sola llamada
(hallazgo 4). Input: `destination`, `checkin`, `checkout`, `adults`,
`children=0`, `limit` (máx. 10). Output: lista de `search_accommodations` +
`status` + `price_from`. Query por lote sobre `room_availabilities`; donde no hay
inventario, mock (ver abajo).

### Precio "desde"
`check_availability` y `search_availability` devuelven
`price_from: { amount, currency, per: "night", source }`. Sale de `rates` cuando hay
tarifas cargadas; si no, mock.

### Datos simulados (mocks)

Para probar el agente de punta a punta sin esperar la carga de inventario y tarifas:

- **Híbrido:** se usa el dato real si existe, y el mock solo donde falta. Así el
  camino real se ejercita desde el día uno.
- **Marcado:** todo dato simulado lleva `source: "mock"` (los reales,
  `source: "real"`). El agente lo comunica como "dato de prueba".
- **Determinístico:** el mock se calcula a partir de `accommodation_id` + fecha, así
  la misma pregunta da la misma respuesta.
- **Apagado en producción:** flag `AI_MOCK_MISSING_DATA` (default `false`); si
  `APP_ENV=production`, se ignora aunque esté en `true`.
- Todo vive en `App\AI\Support\Mock\*`, fácil de borrar.
- Lista de mocks y qué hace falta para reemplazar cada uno: `docs/mcp-mocks-todo.md`.

## Arquitectura (`api/`)

Mismo esquema que `mcp-design.md`, recortado:

```
MCP (laravel/mcp)  ──▶  App\AI\Services\*  ──▶  Models / scopes / policies
 Tools: traducen         5 servicios, Input DTO       visibleTo(), enabled
 args ↔ DTO              + AiContext → Output DTO
```

- `App\AI\Support\AiContext` — se construye desde lo que deja `auth.client`
  (`api_client` + el User en memoria). Los servicios nunca ven `Request`.
- `App\AI\Services\{ListDestinations, SearchAccommodations,
  GetAccommodationDetails, CheckAvailability, SearchAvailability}Service` + DTOs + mappers.
- `AccommodationAvailabilityController` y `client/v1/.../availability` pasan a usar
  `CheckAvailabilityService`, así hay **una sola** lógica de disponibilidad. Ojo:
  el cambio de booleano a tres estados aplica **solo al MCP**; la respuesta HTTP
  actual se mantiene para no romper turinorte.
- **Servidor MCP:** `laravel/mcp`, transporte HTTP en `POST /mcp/traveler`, con
  middleware `json.response`, `auth.client`, `throttle:client` y
  `client.ability:mcp:read`. Antes de instalar, **verificar que `laravel/mcp`
  soporte Laravel 13**; si no, implementar el endpoint JSON-RPC a mano
  (`initialize`, `tools/list`, `tools/call`; son pocas piezas).
- **API key dedicada para el MCP** con ability `mcp:read` únicamente. En la fase de
  chat esa key se le pasa a Anthropic (MCP connector), así que tiene que poder
  revocarse sin tocar la key del BFF.

## Probarlo sin chat

1. `npx @modelcontextprotocol/inspector` → URL `http://localhost:<puerto>/mcp/traveler`,
   header `Authorization: Bearer tk_live_…`. Llamar cada herramienta a mano.
2. Conectarlo a Claude (Desktop o claude.ai) como conector remoto para ver cómo el
   modelo elige herramientas. Requiere URL pública: túnel (`cloudflared` / `ngrok`)
   o staging.
3. Guardar 10–15 preguntas reales de viajero ("algo tranquilo en Cafayate para 2 en
   noviembre", "¿aceptan mascotas en X?") y revisar qué herramientas llama, con qué
   argumentos y dónde se equivoca. Esas preguntas son la base de un eval después.

## Chat en `turinorte/web` (después de validar el MCP)

- Route handler `src/app/api/chat/route.ts` que usa el `AgentLoop` (ver *Agente
  portable*), con streaming. System prompt con el tono de `PRODUCT.md` (anfitrión
  del norte, cálido, rioplatense) y reglas: precios y disponibilidad solo desde
  herramientas, `unknown` → invitar a consultar, datos `mock` → avisar que son de
  prueba, links a `/accommodations/{slug}`.
- Credenciales del LLM y la key MCP dedicada, server-only en Vercel.
- Historial en el navegador (sin cuentas todavía); tope de mensajes por
  conversación y de tool calls por turno.
- Widget de chat simple; los resultados se pueden renderizar como cards usando el
  `slug`.

## Trabajo, secuenciado

1. **Verificar:** levantar `php_api`, contar alojamientos visibles para TuriNorte con
   `room_availabilities` / `rates` / `room_types.max_occupancy`; confirmar
   compatibilidad de `laravel/mcp`.
2. **`App\AI` mínimo:** `AiContext`, 5 servicios, DTOs, mappers, mocks (`AI_MOCK_MISSING_DATA`). El controller de
   disponibilidad delega en el servicio.
3. **Servidor MCP** + ruta + ability `mcp:read` + key dedicada para TuriNorte.
4. **Tests** (`tests/Feature/McpTravelerTest.php`): tenencia (un client no ve
   alojamientos de otro por ninguna herramienta), `enabled = 0` invisible,
   `unknown` sin filas, capacidad filtra room types, key sin `mcp:read` → 403,
   key revocada → 401.
5. **Probar con Inspector y con Claude**, y ajustar las descripciones de las herramientas.
6. **Chat en turinorte.**
7. Actualizar `mcp-design.md` (formato de fechas, `client/v1`, decisión de auth).

## Decisiones (2026-10-01)

1. **Datos faltantes → mocks marcados.** Precio, inventario sin cargar y
   `search_availability` se completan con datos simulados para poder probar el
   agente de punta a punta. Detalle y lista de lo pendiente en
   `docs/mcp-mocks-todo.md`.
2. **El agente no depende de un proveedor de LLM.** Se descarta el MCP connector de
   Anthropic como camino principal: el cliente MCP y el loop de herramientas los
   maneja nuestro código (ver *Agente portable*). Cambiar de proveedor = cambiar de
   adapter.
3. **Idioma:** español por defecto, resuelto por request (no por herramienta). Ver
   *Idioma*.

## Agente portable (reemplaza "dónde corre el loop")

El servidor MCP ya es independiente del proveedor: cualquier cliente MCP lo puede
usar. Lo único que ata a un proveedor es **quién llama al servidor MCP**. Con el
connector de Anthropic, lo llama Anthropic, y ese mecanismo no existe igual en otros
proveedores ni en un LLM propio. Por eso el loop queda de nuestro lado:

```
Chat UI ─▶ /api/chat (Next) ─▶ AgentLoop ─┬─▶ LlmProvider (adapter) ─▶ Anthropic | OpenRouter | LLM propio
                                          └─▶ McpClient ─▶ /mcp/traveler (HotelIgnite)
```

- **`McpClient`**: SDK oficial de MCP para TypeScript. Hace `tools/list` al iniciar y
  `tools/call` cuando el modelo pide una herramienta.
- **`LlmProvider`**: interfaz propia y chica (mensajes + definiciones de
  herramientas → texto en streaming y/o tool calls). Dos adapters cubren todo:
  - `AnthropicProvider` — SDK oficial `@anthropic-ai/sdk` (aprovecha prompt
    caching, thinking y fallbacks).
  - `OpenAICompatibleProvider` — cubre **OpenRouter** y **LLMs propios** servidos
    con vLLM, Ollama o similares, que exponen la API compatible con OpenAI.
- **`AgentLoop`**: llama al provider, ejecuta las tool calls vía `McpClient`, devuelve
  los resultados y repite. Tope de iteraciones por turno.
- Proveedor y modelo por variable de entorno (`LLM_PROVIDER`, `LLM_MODEL`,
  `LLM_BASE_URL`, `LLM_API_KEY`).
- Ventaja extra: en desarrollo no hace falta túnel, porque es Next quien llama a la
  API local.
- **A futuro**, para que cada agencia no tenga que construir su chat, el
  `AgentLoop` se puede mover a un servicio de HotelIgnite sin cambios de
  diseño. Para el MVP vive en turinorte (`src/lib/agent/`).

**Sobre OpenRouter:** sirve para probar varios modelos cambiando un string y con una
sola factura. A cambio: un salto más (latencia y recargo), algunas funciones propias
de cada proveedor quedan parcialmente expuestas, y los mensajes de los viajeros
pasan por un tercero más (relevante para la Ley 25.326). Con el adapter
OpenAI-compatible queda disponible sin elegirlo hoy.

**Sobre un LLM propio:** es viable por la misma vía (OpenAI-compatible). El punto
débil de los modelos abiertos es la calidad del uso de herramientas; las preguntas
guardadas en *Probarlo sin chat* sirven para comparar proveedores con datos.

## Idioma

- El navegador manda el idioma elegido (localStorage, si no `navigator.language`,
  si no `es`) al BFF; el BFF lo pasa al servidor MCP como `Accept-Language` en la
  conexión.
- La API lo resuelve en `AiContext->language` (whitelist de idiomas soportados,
  fallback `es`). Las herramientas usan ese idioma para descripciones y textos; **no**
  es un parámetro que el modelo tenga que recordar.
- El system prompt le indica al modelo responder en ese idioma.
- MVP: solo `es` implementado; el resto cae a `es`.

## Decisiones abiertas

1. **Semántica sin datos de inventario en producción:** con mocks apagados,
   `unknown` comunicado como "consultar" vs no ofrecer esos alojamientos cuando el
   viajero da fechas.
2. **Qué rate plan define el precio "desde"** cuando se reemplace el mock (el más
   barato habilitado, el que tenga desayuno, etc.).
3. **Primer modelo para probar:** propuesta `claude-opus-5-5` con effort `low` vía
   `AnthropicProvider`, y comparar contra otros con las preguntas guardadas.
