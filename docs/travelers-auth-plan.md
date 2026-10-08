# Plan — Cuentas de viajero por client (travelers) (cross-repo)

> Login de viajeros (email + contraseña y Google) en los portales de los clients,
> con la identidad viviendo en la API y cada portal como BFF. Abarca `api/` (camino
> crítico) y `turinorte/web` (primer portal consumidor).
> Fecha: 2026-10-01 · Stack: Laravel 13 / Sanctum 4 · Next 16 (App Router).
> Relacionado: `docs/api-clients-plan.md`, `docs/mcp-design.md` (Grupo A),
> `docs/security-hardening-plan.md`.

## Estado (2026-10-01)

- **turinorte: BFF y UI hechos, contra un backend simulado.** `src/lib/auth/`
  define la interfaz `AuthBackend` (`login`, `me`, `logout`) y un
  `MockAuthBackend` con 3 usuarios de prueba y sesión firmada (HMAC). Route
  handlers `POST /api/auth/login`, `POST /api/auth/logout`, `GET /api/auth/me`;
  cookie `tn_session` httpOnly/SameSite=Lax con `Domain=.<raíz>` bajo el dominio
  raíz; chequeo de `Origin` compartido (`src/lib/http/origin.ts`). UI: botón
  "Ingresar" / menú de cuenta arriba a la derecha (`UserMenu`), diálogo de email
  y contraseña (`LoginDialog`), Google visible como "Próximamente".
  `AUTH_BACKEND` = `mock` | `off` (default: mock en dev, off en prod).
- **Pendiente:** todo `api/` de este plan; en turinorte, un `HoteligniteAuthBackend`
  que implemente la misma interfaz contra `travelers/*` (los route handlers y la
  UI no cambian), registro, recuperar contraseña, `/account`, y rate limit del
  login (el mock no lo tiene).

## Context

Hoy la API conoce dos tipos de identidad: `User` (B2B: `platform`, `account`,
`client`) y la API key del `Client`. El viajero no existe: las pre-reservas son
anónimas y `Guest` es un dato de la reserva, no alguien que inicia sesión.

El objetivo de negocio es que **varias agencias sean clients de HotelIgnite y cada
una opere su portal**. Las cuentas de viajero son el cimiento para:

1. Asociar reservas y consultas a una persona ("mis reservas").
2. El **agente conversacional** del viajero (`mcp-design.md`, Grupo A): con cuenta
   hay límite de uso por persona (control de costo de LLM), memoria/preferencias y
   reserva sin pedir datos en medio del chat.

## Decisiones de diseño

1. **Travelers aislados por client.** `travelers.client_id` + `UNIQUE(client_id,
   email)`. La misma persona en dos portales = dos travelers independientes. Ningún
   client ve los viajeros de otro. Legalmente (Ley 25.326) el client es el
   **responsable** de su base y HotelIgnite el **encargado del tratamiento**.
2. **`Traveler` es un modelo aparte, no un `User`.** `User` arrastra policies,
   abilities y rutas administrativas; mezclarlos abre escalamiento de privilegios.
3. **Tokens propios, no Sanctum.** Tabla `traveler_tokens` con hash sha256, mismo
   patrón que `client_api_keys`. Motivo: un token Sanctum de traveler enviado a
   cualquier grupo `auth:sanctum` autenticaría un modelo que esas rutas no esperan
   (`$request->user()->isClient()` sobre un Traveler). Con tabla propia el traveler
   no puede entrar a ninguna ruta B2B por construcción.
4. **Dos credenciales por request.** `Authorization: Bearer <api key>` sigue
   identificando al client (sin cambios). El viajero viaja en
   **`X-Traveler-Token`**. Middleware nuevo `auth.traveler`, que corre **después**
   de `auth.client` y exige `traveler.client_id === api_client.id`.
5. **Google por ID token, sin Socialite.** El portal usa Google Identity Services
   (botón/One Tap) y obtiene un ID token en el navegador; la API lo verifica
   (firma contra JWKS de Google, `iss`, `exp`, `email_verified`) y exige que `aud`
   sea el **`google_client_id` configurado en ese Client**. Cada agencia usa su
   propio proyecto OAuth (su marca en la pantalla de consentimiento). No hay
   client secret ni redirect URI en ningún lado.
6. **Los mails apuntan al portal, no a la API.** Verificación y reseteo llevan a
   `{client.portal_url}/verify-email?token=…` / `/reset-password?token=…`. La API
   nunca acepta una URL de callback del request (evita phishing con links
   legítimos).
7. **Habilitado por client.** Flag `clients.traveler_auth_enabled` + ability
   `traveler:auth` en la API key. Un client sin eso no ve los endpoints.

## Modelo de datos (`api/`)

**`travelers`**
| Campo | Tipo | Nota |
|---|---|---|
| `id` | bigint | |
| `client_id` | FK clients | tenencia |
| `email` | string | normalizado a minúsculas |
| `email_verified_at` | timestamp null | |
| `password` | string null | null = solo Google |
| `google_sub` | string null | ID estable de Google; base para unificar identidades a futuro |
| `first_name`, `last_name` | string | |
| `phone` | string null | pensado para WhatsApp (fase posterior) |
| `phone_verified_at` | timestamp null | sin uso en MVP |
| `locale` | string(5) default `es` | |
| `last_login_at` | timestamp null | |
| timestamps, `deleted_at` | | soft delete + anonimización (ver baja) |

Índices: `UNIQUE(client_id, email)`, `UNIQUE(client_id, google_sub)`.

**`traveler_tokens`** — sesiones
`id`, `traveler_id`, `token_hash` (unique), `user_agent`, `ip`, `last_used_at`,
`expires_at`, `revoked_at`, timestamps. Formato del token en claro:
`tv_<40 chars random>`; se guarda solo el hash. Vida: **30 días**.

**`traveler_action_tokens`** — verificación de email y reseteo
`id`, `traveler_id`, `type enum('verify_email','reset_password')`, `token_hash`,
`expires_at`, `used_at`, timestamps. Un solo uso. Vencimiento: verify 48 h, reset
60 min. Emitir uno nuevo invalida los anteriores del mismo tipo.
*No se usa el password broker de Laravel*: `password_reset_tokens` está keyeada por
email, y acá el email no es único global.

**`clients`** (+ columnas)
`traveler_auth_enabled bool default false`, `portal_url string null`,
`google_client_id string null`.

**`bookings`** (+ columna)
`traveler_id` FK null. Las pre-reservas anónimas siguen funcionando.

## Endpoints (`routes/client-api.php`)

Todos bajo `json.response`, `auth.client`, `client.ability:traveler:auth` y check de
`traveler_auth_enabled`.

**Públicos (solo API key)**
| Método | Ruta | Nota |
|---|---|---|
| POST | `travelers/register` | `first_name`, `last_name`, `email`, `password` (+confirmation). Crea, manda verificación, devuelve sesión |
| POST | `travelers/login` | `email`, `password` → `{ token, expires_at, traveler }` |
| POST | `travelers/google` | `id_token` → crea, vincula o loguea (ver reglas) |
| POST | `travelers/email/verify` | `token` |
| POST | `travelers/password/forgot` | `email`. **Siempre 202**, exista o no |
| POST | `travelers/password/reset` | `token`, `password` (+confirmation). Revoca todas las sesiones |

**Autenticados (`auth.traveler`)**
| Método | Ruta | Nota |
|---|---|---|
| GET | `travelers/me` | |
| PATCH | `travelers/me` | nombre, apellido, teléfono, locale. El email no se cambia en MVP |
| PUT | `travelers/me/password` | exige la actual (si tiene) |
| DELETE | `travelers/me` | baja: anonimiza datos personales, revoca sesiones |
| POST | `travelers/email/resend` | throttled |
| POST | `travelers/logout` | revoca la sesión actual |
| GET | `travelers/me/bookings` | solo las propias |

**`POST bookings` (existente):** si viene `X-Traveler-Token` válido, setea
`traveler_id` y completa nombre/email/teléfono desde el traveler. Sin token, igual
que hoy.

### Reglas del login con Google

1. Existe traveler con ese `google_sub` en el client → login.
2. Si no, existe con ese email en el client:
   - **email verificado** → vincular (`google_sub`) y login.
   - **email no verificado** → vincular, marcar verificado, **borrar la contraseña
     y revocar sesiones**. Evita el *pre-account hijacking* (alguien registra el
     email de la víctima con su contraseña y espera a que ella entre con Google).
3. Si no existe → crear con `email_verified_at = now()`, sin contraseña.
4. `email_verified=false` en el ID token → 422.

### Rate limiting e IP del viajero

Las requests llegan desde el servidor del portal (Vercel), así que `$request->ip()`
es la IP del BFF, no la del viajero. El BFF manda **`X-End-User-Ip`**, que la API
toma en cuenta **solo después de `auth.client`** (header de un client autenticado,
no de cualquiera; ver `RateLimitSpoofingTest`). Limiters nuevos:
- `traveler-login`: 5/min por `client_id + email + end-user-ip` (login, google).
- `traveler-mail`: 3/hora por `traveler`/email (resend, forgot).
- `traveler-register`: 10/hora por `client_id + end-user-ip`.

### Mails

`TravelerVerifyEmail`, `TravelerResetPassword`, por cola. From: dirección de
HotelIgnite con **nombre de la agencia** como display name. Hoy
`QUEUE_CONNECTION=sync`: funciona, pero el request espera al SMTP; pasar a cola real
antes de producción.

## `turinorte/web` — portal (BFF)

**Lib**
- `src/lib/hotelignite.js`: `catalogGet`/`catalogPost` aceptan un `travelerToken`
  opcional → `X-Traveler-Token`, y siempre mandan `X-End-User-Ip`. Sumar
  `catalogPatch`/`catalogPut`/`catalogDelete` según haga falta.
- `src/lib/session.ts` (server-only): leer/escribir/borrar la cookie
  `tn_session` — `httpOnly`, `Secure`, `SameSite=Lax`, `Path=/`,
  `Domain=.${NEXT_PUBLIC_ROOT_DOMAIN}` (compartida con micrositios), `Max-Age` =
  `expires_at`. Contiene el token opaco; Next no necesita base de datos.

**Route handlers** `src/app/api/auth/*`
`register`, `login`, `google`, `logout`, `me`, `verify-email`, `forgot-password`,
`reset-password`. Igual que `bookings/route.js`: whitelist de campos, pasar los 422
tal cual. En los POST, **validar el header `Origin`** contra el dominio raíz y sus
subdominios (CSRF; `SameSite=Lax` no alcanza entre subdominios). El token nunca
llega al navegador.

**Páginas** (rutas en inglés, como el resto del portal; textos en español)
`/login`, `/signup`, `/forgot-password`, `/reset-password`, `/verify-email`,
`/account` (datos, contraseña, mis reservas, eliminar cuenta).
- Reemplazar el `LoginModal` del template (hoy maqueta en inglés, en `Header3`).
- Header: estado de sesión vía `GET /api/auth/me` **del lado del cliente**, para que
  leer la cookie no vuelva dinámicas las páginas del catálogo.
- Micrositios: "Ingresar" lleva a `turinorte.com/login?next=<url>`. Validar `next`
  (solo dominio raíz o sus subdominios) contra open redirect.
- Formulario de consulta/reserva: prellenar con el traveler si hay sesión.

**Google**
Script `https://accounts.google.com/gsi/client`, botón oficial. Variable
`NEXT_PUBLIC_GOOGLE_CLIENT_ID` (el client ID no es secreto). En Google Cloud:
proyecto de TuriNorte, pantalla de consentimiento, *Authorized JavaScript origins*:
`https://turinorte.com`, `https://www.turinorte.com`, `http://localhost:3000`.
Cargar el mismo client ID en `clients.google_client_id`.

**Legal**
Actualizar `/policies` (qué se guarda, para qué, derechos de acceso/rectificación/
supresión) y registrar la base ante la AAIP.

## Trabajo, secuenciado

1. **api — esquema y modelo**: migraciones, `Traveler`, `TravelerToken`,
   `TravelerActionToken`, columnas en `clients` y `bookings`.
2. **api — auth email/contraseña**: middleware `auth.traveler`, register/login/
   logout/me, verify y reset, mails, limiters, `X-End-User-Ip`.
3. **api — Google**: verificador de ID token (JWKS cacheado), reglas de vinculación.
4. **api — integración**: `traveler_id` en `POST bookings`, `me/bookings`, baja.
   Ability `traveler:auth` y campos nuevos en el admin de clients (`ClientController`).
5. **api — OpenAPI**: documentar en `docs/openapi.yaml`.
6. **turinorte — BFF**: `session.ts`, `hotelignite.js`, route handlers.
7. **turinorte — UI**: páginas, header, prellenado, Google.
8. **turinorte — legal y config**: policies, Google Cloud, variables en Vercel.

## Tests (`api/tests/Feature/TravelerAuthTest.php` y afines)

- **Aislamiento**: token de traveler del client A + API key del client B → 401.
  Mismo email registrable en A y en B sin conflicto.
- Token de traveler enviado como `Bearer` a rutas `auth:sanctum` → 401.
- Client sin `traveler_auth_enabled` o key sin `traveler:auth` → 403.
- Google: `aud` de otro client → 401; `email_verified=false` → 422; vinculación
  con cuenta no verificada borra la contraseña y revoca sesiones.
- `forgot` responde 202 con email inexistente; tokens de acción de un solo uso y
  vencidos → 422; reset revoca sesiones.
- `X-End-User-Ip` ignorado sin `auth.client`; limiters por end-user IP.
- `POST bookings` con token setea `traveler_id`; `me/bookings` no muestra ajenas.

## Decisiones abiertas

1. **¿Login antes de verificar el email?** Propuesta: sí, con aviso en `/account`;
   exigir verificación solo para lo que lo necesite (p. ej. reservar desde el agente).
2. **Enumeración en registro**: devolver "email ya registrado" (mejor UX, revela
   existencia) o respuesta genérica + mail al dueño. Propuesta: mensaje explícito,
   con el limiter de registro como mitigación.
3. **Librería JWT**: `firebase/php-jwt` + JWKS propio cacheado (liviano) vs
   `google/apiclient` (pesado). Propuesta: `firebase/php-jwt`.
4. **Remitente de mails**: dominio de HotelIgnite con nombre de la agencia (MVP) vs
   dominio propio de cada agencia (requiere SPF/DKIM por client).
5. **Vida de sesión**: 30 días fijos vs renovación deslizante en cada uso.

## Lo que NO entra (fases posteriores)

Agente conversacional (siguiente plan; se apoya en `auth.traveler` y en
`mcp-design.md` Grupo A, que hay que migrar de `/v1` a `client/v1`), WhatsApp y
verificación de teléfono, cambio de email, identidad unificada entre clients,
2FA, exportación de datos del viajero, consultas (`Inquiry`) por la client API.
