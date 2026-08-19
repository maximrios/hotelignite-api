# Diseño funcional — MCP para HotelIgnite API

> Estado: **diseño** (sin implementar). Este documento define las herramientas
> MCP de alto nivel que expondrán la API a asistentes de IA. No es un wrapper
> endpoint-por-endpoint: cada herramienta cubre un caso de uso completo.
>
> Relacionado: `docs/api-readiness.md`, `docs/api-clients-plan.md`,
> `docs/security-hardening-plan.md`.

## Principios de diseño

- **Una herramienta = un caso de uso completo**, no un endpoint. Varios verbos
  (create/update/get) se agrupan cuando pertenecen al mismo flujo mental del
  asistente.
- **La tenencia y los permisos viven en el servidor.** El MCP solo transporta el
  token Sanctum del usuario; `scopeVisibleTo`, `AccommodationPolicy`,
  `client.readonly` y los middlewares `platform` / `client.user` deciden qué ve
  y qué puede escribir cada quién. El asistente **nunca** filtra por cuenta desde
  el cliente.
- **Cuatro personas** determinan la prioridad: (1) asistente del **viajero /
  huésped**, (2) asistente del **hotelero** (dueño de cuenta), (3) **staff de
  plataforma**, (4) **client B2B** (agencias / gobiernos).
- **Excluidos del MCP** por decisión explícita: el CRUD legacy `/api/v1/accounts`
  (expone `token`, filtros mentirosos), las rutas `/pms/*` (deshabilitadas), y los
  duplicados legacy de policies (`accommodation-policy-links`, `-translations`,
  `-old`). Se usan siempre las variantes `admin/v1` y las canónicas.

---

## Grupo A — Descubrimiento y reserva (asistente del viajero)

### 1. `search_accommodations` — Prioridad: **Alta**
- **Descripción:** Busca alojamientos publicables por destino, tipo y texto libre.
  Punto de entrada del flujo de reserva conversacional.
- **Endpoints:** `GET /v1/accommodations`, `GET /v1/web/accommodations`,
  `GET /v1/accommodations/types`, `GET /v1/cities`, `GET /v1/states`
- **Parámetros:** `search?`, `city_slug?`, `accommodation_type?` (agrupa 1–5 como
  "hoteles por estrellas"), `page?`, `limit?`
- **Respuesta ideal:** lista paginada con `slug`, `name`, `city`, `type`, `stars`,
  imagen principal, `allow_bookings`, rango de precio orientativo si existe. El
  `slug` es la clave para el siguiente paso.
- **Auth:** Sí (Sanctum). *Nota: hoy va bajo `auth:sanctum`; si el destino es un
  asistente público de reservas, evaluar una variante pública.*

### 2. `get_accommodation_details` — Prioridad: **Alta**
- **Descripción:** Ficha completa de un alojamiento (servicios, políticas,
  descripciones, imágenes) para responder consultas del huésped.
- **Endpoints:** `GET /v1/accommodation/{slug}`,
  `GET /v1/accommodations/{id}/services`, `GET /v1/accommodations/{id}/policies`,
  `GET /v1/accommodation-descriptions?accommodation_id=`
- **Parámetros:** `slug` (requerido), `language?` (default `es`)
- **Respuesta ideal:** objeto único consolidado en **una** respuesta con
  descripción multilenguaje, amenities, políticas (cancelación, mascotas,
  check-in/out) e imágenes.
- **Auth:** Sí

### 3. `check_availability` — Prioridad: **Alta**
- **Descripción:** Verifica disponibilidad para fechas y ocupación, y devuelve un
  **token de disponibilidad** (válido 15 min) que habilita la pre-reserva.
- **Endpoints:** `GET /v1/accommodations/{id}/availability`
- **Parámetros:** `accommodation_id` (req.), `checkin` (`d/m/Y`), `checkout`,
  `adults`, `children?`
- **Respuesta ideal:** `{ available, token, nights, expires_at }`. El asistente
  encadena el `token` efímero a `manage_booking`.
- **Auth:** Sí

### 4. `manage_booking` — Prioridad: **Alta**
- **Descripción:** Flujo de pre-reserva en dos pasos: (1) crear con
  fechas/ocupación, (2) completar datos del huésped. Consulta estado por token.
- **Endpoints:** `POST /v1/booking`, `PUT /v1/booking`, `GET /v1/booking`,
  `GET /v1/bookings`
- **Parámetros:** `action` ∈ {create, complete, get, list}; create:
  `accommodation_id`, `room_id?`, `tour_id?`, `checkin`, `checkout`, `adults`,
  `children?`, `availability_token?`; complete: `token`, `name`, `lastname`,
  `email`, `phone`
- **Respuesta ideal:** Booking con `uuid`, estado del flujo (paso 1 vs 2) y datos
  confirmados. Errores claros cuando el token expiró.
- **Auth:** Sí

### 5. `submit_inquiry` — Prioridad: **Media**
- **Descripción:** Envía una consulta/lead cuando el alojamiento no tiene motor de
  reservas o el huésped prefiere contacto directo.
- **Endpoints:** `POST /v1/inquiries`
- **Parámetros:** `accommodation_id`, `name`, `email`, `message`, `checkin?`,
  `checkout?`, `phone?`
- **Respuesta ideal:** confirmación de recepción con id de la consulta.
- **Auth:** Sí

---

## Grupo B — Gestión del alojamiento (asistente del hotelero)

### 6. `manage_accommodation_profile` — Prioridad: **Alta**
- **Descripción:** Alta, edición y publicación de la ficha del alojamiento (datos,
  descripciones, servicios, imágenes, políticas).
- **Endpoints:** `GET/POST/PUT/PATCH/DELETE /admin/v1/accommodations{/id}`,
  `.../services`, `.../images` (+ `reorder`), `/v1/accommodation-descriptions`,
  `/admin/v1/accommodation-policies`
- **Parámetros:** `action`, `accommodation_id?`, `data{}`, `language?`, filtros de
  listado (`search`, `enabled`, `account_id` solo platform)
- **Respuesta ideal:** recurso actualizado con relaciones (`city`, `state`,
  `type`) cargadas y confirmación de qué cambió. Respeta `client.readonly`.
- **Auth:** Sí (policy + scoping por cuenta)

### 7. `manage_room_inventory` — Prioridad: **Alta**
- **Descripción:** Gestiona tipos de habitación, habitaciones físicas, camas,
  descripciones y servicios por tipo.
- **Endpoints:** `GET/POST/PUT/DELETE /v1/room-types`, `/v1/rooms`,
  `/v1/room-type-descriptions`, `/v1/room-type-beds`, `/v1/room-type-services`
- **Parámetros:** `entity` ∈ {room_type, room, bed, description, service},
  `action`, `accommodation_id`, `room_type_id?`, `data{}`, `language?`
- **Respuesta ideal:** objeto creado/modificado con categoría, camas y servicios
  anidados.
- **Auth:** Sí
- ⚠️ **Bloqueante conocido:** `rooms` / `room_types` sin timestamps en la DB de dev
  rompen POST/PUT (ver `MEMORY.md → Divergencias de esquema`). Validar antes de
  exponer escritura.

### 8. `manage_rates_and_availability` — Prioridad: **Alta**
- **Descripción:** Núcleo de revenue management: planes tarifarios, precios por
  fecha y cupos/cierres de disponibilidad.
- **Endpoints:** `GET/POST/PUT/DELETE /v1/rate-plans`, `/v1/rates`,
  `/v1/room-availability`
- **Parámetros:** `entity` ∈ {rate_plan, rate, availability}, `action`,
  `room_type_id`, `date_from`, `date_to`, `price?`, `available?`, `closed?`
- **Respuesta ideal:** rango de fechas afectado con estado resultante (precio,
  cupo, abierto/cerrado). Idealmente soportar operaciones por rango, no día a día.
- **Auth:** Sí

### 9. `list_reservations` — Prioridad: **Media**
- **Descripción:** Consulta reservas confirmadas con filtros de fecha/estado/canal.
- **Endpoints:** `GET /v1/reservations` (+ `GET /v1/bookings` para pre-reservas)
- **Parámetros:** `date_from?`, `date_to?`, `status?`, `channel?`,
  `accommodation_id?`, `page?`
- **Respuesta ideal:** lista con huésped (`full_name`), fechas, `pax`, canal y
  estado. *Hoy solo hay `index`; detalle o cambio de estado requiere ampliar la
  API.*
- **Auth:** Sí

---

## Grupo C — Catálogos de referencia

### 10. `list_reference_catalogs` — Prioridad: **Media**
- **Descripción:** Herramienta única para poblar selectores y resolver ids.
- **Endpoints:** `GET /admin/v1/services`, `/states`, `/cities`, `/account-types`,
  `/plans`, `/accommodation-types`, `/document-types`, `/v1/policies`,
  `/v1/channels` (público)
- **Parámetros:** `catalog` (req.), `search?`, `country_iso?` (para states)
- **Respuesta ideal:** lista `{id, name/slug}` compacta. Encapsula la trampa de
  `states` (catálogo mundial de 4119 filas → siempre filtrar por país) y
  `cities.state_id = 0` como "sin provincia".
- **Auth:** Sí (excepto channels)

---

## Grupo D — Administración de plataforma (asistente del staff)

### 11. `manage_accounts` — Prioridad: **Media**
- **Descripción:** ABM de cuentas hoteleras (tenants).
- **Endpoints:** `GET/POST/PATCH/DELETE /admin/v1/accounts{/id}` (variante buena,
  con `AccountPolicy`, sin exponer `token`)
- **Parámetros:** `action`, `account_id?`, `data{}` (name, plan_id,
  account_type_id…), `search?`, `page?`
- **Respuesta ideal:** cuenta con plan y contadores de accommodations/users.
  `delete` solo cuentas vacías (no hay FK, evita huérfanos).
- **Auth:** Sí — middleware `platform`

### 12. `manage_users` — Prioridad: **Media**
- **Descripción:** ABM de usuarios (staff de plataforma, de cuenta y de client).
- **Endpoints:** `GET/POST/PATCH/DELETE /admin/v1/users{/id}`
- **Parámetros:** `action`, `user_id?`, `data{}` (name, email, user_type,
  account_id/client_id, password), `search?`
- **Respuesta ideal:** usuario con su rol/tenencia; nunca devuelve secretos.
- **Auth:** Sí — `platform`

### 13. `manage_b2b_clients` — Prioridad: **Media**
- **Descripción:** Gestiona clients B2B, su padrón de alojamientos y sus API keys
  de acceso M2M.
- **Endpoints:** `GET/POST/PATCH /admin/v1/clients{/id}`,
  `POST/DELETE .../accommodations`, `POST/DELETE .../keys`
- **Parámetros:** `action` ∈ {list, get, create, update, attach_accommodations,
  detach_accommodation, create_key, revoke_key}, `client_id?`,
  `accommodation_ids?`, `key_id?`, `data{}`
- **Respuesta ideal:** client con padrón y keys (la key en claro **solo** al
  crearla). Revocación confirmada.
- **Auth:** Sí — `platform`

---

## Grupo E — Legajo documental y red B2B

### 14. `manage_accommodation_documents` — Prioridad: **Baja**
- **Descripción:** Legajo del alojamiento: subir/listar/descargar documentos y
  compartirlos con clients B2B bajo consentimiento.
- **Endpoints:** `GET /admin/v1/document-types`, `GET/POST/DELETE .../documents`,
  `.../download`, `GET .../sharing`,
  `POST/DELETE .../documents/{doc}/shares/{clientId}`
- **Parámetros:** `action`, `accommodation_id`, `document_id?`, `document_type?`,
  `client_id?`, `file?`
- **Respuesta ideal:** documento con tipo, estado de compartición y URL/stream de
  descarga. *Plan aún en diseño — ver `docs/documents-plan.md`.*
- **Auth:** Sí

### 15. `manage_associate_network` — Prioridad: **Media**
- **Descripción:** Lado client B2B: invitar hoteleros, ver padrón de asociados y
  acceder a documentos/requisitos compartidos. Incluye el flujo público de
  aceptación de invitación por token.
- **Endpoints:** `POST /client-panel/v1/invitations` (+ `/resend`),
  `GET .../associates`, `GET .../document-requirements`,
  `GET .../associates/{acc}/documents` (+ download); público:
  `GET/POST /v1/invitations/{token}` (`accept`/`decline`)
- **Parámetros:** `action` ∈ {invite, resend, list_associates, list_requirements,
  list_documents, download_document, show_invitation, accept_invitation,
  decline_invitation}, `email?`, `token?`, `accommodation_id?`
- **Respuesta ideal:** invitación con estado, padrón de asociados, checklist de
  requisitos. El flujo público devuelve el mínimo y valida tenencia server-side.
- **Auth:** Mixta — panel: `auth:sanctum` + `client.user`; aceptación: **pública**
  por token

---

## Grupo F — Autenticación y perfil

### 16. `get_current_profile` — Prioridad: **Alta**
- **Descripción:** Devuelve el usuario autenticado y permite actualizar
  perfil/contraseña. Deja al asistente saber "quién soy y qué rol tengo" para
  adaptar qué herramientas ofrecer.
- **Endpoints:** `GET /user`, `PATCH /user`, `PUT /user/password`
- **Parámetros:** `action` ∈ {get, update_profile, update_password}, `data{}`
- **Respuesta ideal:** `{ id, name, email, user_type, account_id/client_id }`. El
  `user_type` orienta al asistente sobre el grupo de herramientas relevante.
- **Auth:** Sí. El `login` queda **fuera** del MCP: el token se inyecta en la
  config del servidor, no se pide contraseña en la conversación.

---

## Resumen de prioridades

| Prioridad | Herramientas |
|-----------|--------------|
| **Alta**  | 1 search · 2 details · 3 availability · 4 booking · 6 profile · 7 inventory · 8 rates · 16 profile |
| **Media** | 5 inquiry · 9 reservations · 10 catalogs · 11 accounts · 12 users · 13 clients · 15 associates |
| **Baja**  | 14 documents |

## Caminos de adopción sugeridos

1. **MVP viajero** (1–4 + 16): mayor impacto, menor riesgo — un asistente que
   busca, informa y reserva.
2. **MVP hotelero** (6–8): pone la IA a operar revenue e inventario, donde
   HotelIgnite se diferencia.

## Puntos a resolver antes de implementar

- Divergencia de esquema en `rooms` / `room_types` bloquea la herramienta 7 en dev.
- `reservations` solo tiene `index`: sin detalle ni cambio de estado sin ampliar
  la API.
- Modelo de auth del MCP: **token Sanctum por usuario** (multi-tenant real,
  recomendado — reutiliza el scoping del servidor) vs. **API key de client B2B**
  (M2M).

---

# Arquitectura — Capa de servicios AI (`App\AI`)

> Objetivo: **desacoplar por completo el MCP de la API**. Cuando exista el
> servidor MCP debe hablar solo con `App\AI`, nunca con controladores, rutas,
> `Request` ni `JsonResource`.

## La costura del desacople

```
┌─────────────────────────────────────────────────────────┐
│  MCP Server (adapter, fuera de scope todavía)            │
│  - traduce tool-call JSON-RPC → llamada a Service         │
│  - lee el ToolRegistry para el schema de cada herramienta │
└───────────────┬─────────────────────────────────────────┘
                │  Input DTO + AiContext          Output DTO
                ▼                                     ▲
┌─────────────────────────────────────────────────────────┐
│  App\AI\Services\*   (16 servicios, un caso de uso c/u)  │
│  - orquestan, autorizan (Gate), validan, mapean a DTO     │
└───────────────┬─────────────────────────────────────────┘
                │
                ▼
┌─────────────────────────────────────────────────────────┐
│  App\Repositories\Contracts\*   (los que ya existen)     │
│  App\Models\*  ·  Gate/Policies  ·  abstracciones nuevas  │
└─────────────────────────────────────────────────────────┘
```

**Reglas duras del acople:**

1. Un Service **jamás** recibe `Illuminate\Http\Request` ni devuelve
   `JsonResource`/`ResourceCollection`. Recibe un **Input DTO** + un
   **`AiContext`**, devuelve un **Output DTO** plano.
2. Los repositorios actuales devuelven `JsonResource` → la capa AI **no los
   consume tal cual**. Se añaden métodos `*ForAi()` (o mappers) que devuelven
   modelos/arrays, y `App\AI\Mappers` los convierte a Output DTO. Así el contrato
   del MCP es estable aunque cambien los Resources de la API.
3. La autenticación no viaja como token crudo dentro del Service: el adapter
   resuelve el usuario Sanctum y construye un `AiContext`; el Service usa
   `Gate::forUser($ctx->user)` para respetar las policies existentes
   (`AccommodationPolicy`, `AccountPolicy`, `client.readonly`, `platform`).

## Estructura de carpetas

```
app/AI/
├── Contracts/          AiTool (marker), AiToolInput, AiToolOutput
├── Support/
│   ├── AiContext.php           user + helpers isPlatform()/isClient()/accountId()/clientId()
│   ├── ToolRegistry.php        name → [Service, Input, Output, requiresAuth, priority]
│   ├── AvailabilityTokenStore  wrap de Cache (token de 15 min)
│   ├── FileStorage             wrap de Storage (documentos)
│   └── Pagination/…            PaginationMeta, ListFilters
├── Validation/
│   └── InputValidator.php      envuelve Validator::make → AiValidationException
├── Exceptions/         AiToolException (base), AiValidationException,
│                       AiAuthorizationException, AiResourceNotFoundException
├── DTO/
│   ├── Input/          un *Input por herramienta
│   └── Output/         *Output + Items/ (AccommodationSummary, ServiceItem, …)
├── Mappers/            Eloquent → Output DTO
└── Services/           (agrupados por dominio, 16 servicios)
```

**Building blocks transversales:**

- **`AiContext`** — `readonly`, lo construye el adapter. Todo Service lo recibe.
  Reemplaza a `$request->user()`.
- **DTOs** — clases `readonly`, inmutables. Input:
  `public static function fromArguments(array $args, AiContext $ctx): self` que
  corre `InputValidator` con `rules()`/`messages()` y lanza `AiValidationException`
  estructurada. Output: `toArray()` para serializar al MCP.
- **`ToolRegistry`** — única fuente de verdad de qué herramientas existen y su
  metadata (nombre, DTOs, `requiresAuth`, prioridad). El MCP lee de acá; nunca
  importa un controller.
- **Excepciones tipadas** — el adapter las mapea a errores MCP (validation →
  invalid params, authorization → permission denied, not found → …). Los Services
  no devuelven códigos HTTP.
- **Escrituras** — envueltas en `DB::transaction` dentro del Service (o vía
  decorator).
- **Registro** — nuevo `App\Providers\AiServiceProvider`: autowire por DI + puebla
  el `ToolRegistry`.

## Especificación por herramienta

> Convención: todo Input se valida en `fromArguments()`; todo Output es DTO plano
> mapeado desde Eloquent (no Resource). Solo se detalla lo específico de cada uno.

### Grupo A — Viajero

**1. `search_accommodations`**
- **Service:** `Services\Search\SearchAccommodationsService`
- **Input DTO:** `SearchAccommodationsInput { ?string search, ?string citySlug, ?int accommodationType, int page=1, int limit=15 }`
- **Output DTO:** `AccommodationSearchResult { AccommodationSummary[] items, PaginationMeta meta }`
- **Validaciones:** `search` nullable|string|max:120 · `citySlug` nullable|exists:cities,slug · `accommodationType` nullable|integer|between:1,{max} · `page` integer|min:1 · `limit` integer|between:1,50
- **Dependencias:** `AccommodationInterface`, `CityInterface` (slug→id), `AiContext` (aplica `scopeVisibleTo`), `Mappers\AccommodationMapper`

**2. `get_accommodation_details`**
- **Service:** `Services\Accommodations\GetAccommodationDetailsService`
- **Input DTO:** `GetAccommodationDetailsInput { string slug, string language='es' }`
- **Output DTO:** `AccommodationDetail { …core, DescriptionItem description, ServiceItem[] services, PolicyItem[] policies, ImageItem[] images }`
- **Validaciones:** `slug` required|string · `language` in:{config idiomas}
- **Dependencias:** `AccommodationInterface`, `AccommodationServiceInterface`, `AccommodationPolicyInterface`, `AccommodationDescriptionInterface`

**3. `check_availability`**
- **Service:** `Services\Availability\CheckAvailabilityService`
- **Input DTO:** `CheckAvailabilityInput { int accommodationId, CarbonImmutable checkin, CarbonImmutable checkout, int adults, int children=0 }`
- **Output DTO:** `AvailabilityResult { bool available, ?string token, ?int nights, ?CarbonImmutable expiresAt }`
- **Validaciones:** `accommodationId` required|exists · `checkin` date_format:d/m/Y|after_or_equal:today · `checkout` after:checkin · `adults` min:1 · `children` min:0 · noches ≤ {config max_stay}
- **Dependencias:** `AccommodationInterface`, `RoomAvailabilityInterface`, `Support\AvailabilityTokenStore` (⟵ desacopla del `Cache` inline del controller)

**4. `manage_booking`**
- **Service:** `Services\Bookings\ManageBookingService` (dispatch interno a handlers `create`/`complete`/`get`/`list`)
- **Input DTO:** `ManageBookingInput { BookingAction action, …campos condicionales }` (enum `BookingAction`)
- **Output DTO:** `BookingResult { string uuid, string step, BookingDetails details }` | `BookingListResult`
- **Validaciones:** `action` enum · create: `accommodationId` exists, `checkin/checkout` d/m/Y, `adults`, `availabilityToken?` · complete: `token` uuid + `name/lastname/email(email)/phone` · get: `token`
- **Dependencias:** `BookingInterface`, `AvailabilityTokenStore` (consume el token de disponibilidad), `AccommodationInterface`

**5. `submit_inquiry`**
- **Service:** `Services\Inquiries\SubmitInquiryService`
- **Input DTO:** `SubmitInquiryInput { int accommodationId, string name, string email, string message, ?CarbonImmutable checkin, ?CarbonImmutable checkout, ?string phone }`
- **Output DTO:** `InquiryResult { int id, string status }`
- **Validaciones:** `accommodationId` exists · `name` max:120 · `email` email · `message` max:2000 · fechas nullable|date_format
- **Dependencias:** `InquiryInterface`, `AccommodationInterface`

### Grupo B — Hotelero

**6. `manage_accommodation_profile`**
- **Service:** `Services\Accommodations\ManageAccommodationProfileService`
- **Input DTO:** `ManageAccommodationProfileInput { ProfileAction action, ?int accommodationId, ?ProfileData data, ?string language, ?ListFilters filters }`
- **Output DTO:** `AccommodationDetail` | `AccommodationSearchResult` | `DeletionResult`
- **Validaciones:** `action` enum · create/update: reglas del set de campos (name, type_id, city_id, state_id, enabled…) · **autorización** `Gate::forUser->authorize(view/create/update/delete)` · `client.readonly` bloquea escritura (rol client → `AiAuthorizationException`)
- **Dependencias:** `AccommodationInterface`, `AccommodationServiceInterface`, `AccommodationDescriptionInterface`, `AccommodationPolicyInterface`, `Gate`, `AiContext` — ⚠️ imágenes requieren **nuevo** `AccommodationImageInterface` (ver gaps)

**7. `manage_room_inventory`**
- **Service:** `Services\Rooms\ManageRoomInventoryService`
- **Input DTO:** `ManageRoomInventoryInput { InventoryEntity entity, InventoryAction action, int accommodationId, ?int roomTypeId, ?array data, ?string language }`
- **Output DTO:** `InventoryResult` (envuelve `RoomTypeDetail`|`RoomItem`|`BedItem`|`DescriptionItem`|`ServiceItem`)
- **Validaciones:** `entity`/`action` enums · reglas condicionales por entidad · **ownership**: el accommodation pertenece al actor · ⚠️ precondición de esquema (timestamps rotos en dev)
- **Dependencias:** `RoomTypeInterface`, `RoomInterface`, `RoomTypeBedInterface`, `RoomTypeDescriptionInterface`, `RoomTypeServiceInterface`, `AccommodationInterface`, `Gate`

**8. `manage_rates_and_availability`**
- **Service:** `Services\Revenue\ManageRatesAndAvailabilityService`
- **Input DTO:** `ManageRatesInput { RevenueEntity entity, RevenueAction action, int roomTypeId, ?CarbonImmutable dateFrom, ?CarbonImmutable dateTo, ?int price, ?int available, ?bool closed, ?int ratePlanId }`
- **Output DTO:** `RateRangeResult { …resumen de días afectados }` | `RatePlanDetail`
- **Validaciones:** enums · `dateTo` ≥ `dateFrom` · rango ≤ {config max_range} · `price/available` ≥ 0 · roomType pertenece al accommodation del actor
- **Dependencias:** `RatePlanInterface`, `RateInterface`, `RoomAvailabilityInterface`, `RoomTypeInterface` (resolver ownership), `Gate`

**9. `list_reservations`**
- **Service:** `Services\Reservations\ListReservationsService`
- **Input DTO:** `ListReservationsInput { ?CarbonImmutable dateFrom, ?CarbonImmutable dateTo, ?int statusId, ?int channelId, ?int accommodationId, int page=1, int limit=15 }`
- **Output DTO:** `ReservationListResult { ReservationSummary[] items, PaginationMeta meta }`
- **Validaciones:** rango de fechas · ids exist · `accommodationId` dentro del scope del actor
- **Dependencias:** `ReservationInterface`, `BookingInterface` (pre-reservas), `AiContext`

### Grupo C — Catálogos

**10. `list_reference_catalogs`**
- **Service:** `Services\Catalogs\ListReferenceCatalogsService`
- **Input DTO:** `ListReferenceCatalogsInput { CatalogType catalog, ?string search, ?string countryIso }`
- **Output DTO:** `CatalogResult { CatalogItem[] items }`
- **Validaciones:** `catalog` enum required · `countryIso` requerido cuando `catalog=states` (default `config('api.catalog_country_iso')`)
- **Dependencias:** `ServiceInterface`, `PolicyInterface`, `AccommodationTypeInterface`, `CityInterface`, `ChannelInterface` + **repos read nuevos** para states/plans/account-types/document-types (hoy sin interface)

### Grupo D — Plataforma

**11. `manage_accounts`**
- **Service:** `Services\Platform\ManageAccountsService`
- **Input DTO:** `ManageAccountsInput { AccountAction action, ?int accountId, ?AccountData data, ?ListFilters filters }`
- **Output DTO:** `AccountDetail` | `AccountListResult` | `DeletionResult`
- **Validaciones:** solo platform (`$ctx->isPlatform()` sino `AiAuthorizationException`) · `AccountPolicy::delete` exige cuenta vacía · reglas de create/update · **Output nunca expone `token`**
- **Dependencias:** `AccountInterface` (o repo admin nuevo), `Gate` (`AccountPolicy`), `AiContext`

**12. `manage_users`**
- **Service:** `Services\Platform\ManageUsersService`
- **Input DTO:** `ManageUsersInput { UserAction action, ?int userId, ?UserData data, ?ListFilters filters }`
- **Output DTO:** `UserDetail` | `UserListResult` | `DeletionResult`
- **Validaciones:** solo platform · `email` unique · `user_type` enum · coherencia `account_id`/`client_id` según tipo · reglas de password · **nunca** devolver secretos
- **Dependencias:** **nuevo** `UserManagementInterface` (hoy no hay `UserInterface`), `Gate`, `AiContext`

**13. `manage_b2b_clients`**
- **Service:** `Services\Platform\ManageB2bClientsService`
- **Input DTO:** `ManageClientsInput { ClientAction action, ?int clientId, ?ClientData data, ?int[] accommodationIds, ?int keyId }`
- **Output DTO:** `ClientDetail` | `ClientListResult` | `ClientKeyCreated { string plaintextKey /* solo al crear */ }`
- **Validaciones:** solo platform · `accommodationIds` exist · la key en claro se devuelve **una única vez** · revoke idempotente
- **Dependencias:** **nuevos** `ClientInterface` + `ClientApiKeyInterface` (hoy no existen), `Gate`, `AiContext`

### Grupo E — Legajo y red B2B

**14. `manage_accommodation_documents`**
- **Service:** `Services\Documents\ManageAccommodationDocumentsService`
- **Input DTO:** `ManageDocumentsInput { DocumentAction action, int accommodationId, ?int documentId, ?int documentTypeId, ?int clientId, ?FileRef file }`
- **Output DTO:** `DocumentDetail` | `SharingState` | `DownloadRef`
- **Validaciones:** ownership del accommodation · mime/size del archivo · consentimiento para compartir · descarga como referencia/stream, no base64 salvo archivos chicos
- **Dependencias:** `DocumentInterface`, `AccommodationInterface`, `Support\FileStorage` (⟵ desacopla de `Storage`), `Gate`

**15. `manage_associate_network`**
- **Service:** `Services\ClientPanel\ManageAssociateNetworkService` (+ `PublicInvitationService` para el flujo por token)
- **Input DTO:** `ManageAssociateNetworkInput { NetworkAction action, ?string email, ?string token, ?int accommodationId, ?int invitationId }`
- **Output DTO:** `InvitationDetail` | `AssociateListResult` | `RequirementListResult` | `DocumentListResult`
- **Validaciones:** acciones del panel → rol client (`$ctx->isClient()` + `clientId`) · acciones públicas (show/accept/decline) → solo `token` válido, sin `AiContext` · `email` email
- **Dependencias:** `InvitationInterface`, `DocumentInterface`, `AccommodationInterface`, `AiContext`

### Grupo F — Perfil

**16. `get_current_profile`**
- **Service:** `Services\Profile\GetCurrentProfileService`
- **Input DTO:** `ProfileInput { ProfileAction action, ?ProfileData data }`
- **Output DTO:** `ProfileResult { int id, string name, string email, string userType, ?int accountId, ?int clientId }`
- **Validaciones:** update_profile → reglas de campos · update_password → current + new confirmado · **nunca** devolver token/hash
- **Dependencias:** `AiContext` (ya trae el user), `User` model para escrituras (lógica extraída de `UserAuthController`)

## Gaps de dependencias a resolver (antes de codear la capa)

1. **Mapeo Eloquent → Output DTO.** Los repos devuelven `JsonResource`. Decidir:
   (a) agregar métodos `*ForAi()` que devuelvan modelos, o (b) que los `Mappers`
   consuman los modelos ya cargados. Recomendado (b) + métodos read que devuelvan
   Eloquent — mantiene el contrato MCP independiente de la API.
2. **Interfaces faltantes:** `AccommodationImageInterface`, `UserManagementInterface`,
   `ClientInterface`, `ClientApiKeyInterface`, y repos read para
   `state`/`plan`/`account_type`/`document_type`. Hoy esos controllers usan
   Eloquent directo; la capa AI necesita un contrato estable.
3. **Abstracciones nuevas:** `AvailabilityTokenStore` (wrap de `Cache`) y
   `FileStorage` (wrap de `Storage`) — sacan facades de dentro de los Services.
4. **Bloqueante conocido:** divergencia de esquema `rooms`/`room_types` (sin
   timestamps) frena las escrituras de la herramienta 7 en dev.
5. **`reservations` solo tiene `index`** → la herramienta 9 nace read-only hasta
   ampliar la API.
