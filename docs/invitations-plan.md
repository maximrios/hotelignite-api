# Plan — Invitaciones y ciclo de vida del asociado (cross-repo)

Plan de implementación del flujo de invitación, alta, completado y habilitación de
asociados. Abarca `api/` (camino crítico), `pms/`, `crm/` y `clients/`.

La spec de diseño vive en `api/.claude/skills/invitations.md`. Este documento la
baja a trabajo secuenciado y registra dos decisiones que la refinan (ver *Deltas*).

## Context

Hoy un alojamiento entra al sistema solo si el staff lo carga a mano: no escala y
el dato lo tiene el client (un municipio con 200 prestadores). La inversión es que
**el client invita, el hotelero se da de alta y completa, y el client habilita**.

Dos decisiones tomadas en la sesión de diseño refinan la spec:

1. **Se elimina el gate bloqueante de staff (encuadre).** El único gate de
   publicación es el "Habilitar" manual del client (`verified_at`). El motivo: la
   relación con el asociado es del client, no del staff, y el gate de staff hacía a
   la plataforma cuello de botella (200 prestadores = cola de 200).
2. **El client no crea `Accommodation`.** Invita por `name` + email; el
   `Accommodation` nace al aceptar (caso A). El client solo escribe en `invitations`,
   nunca toca el catálogo.

Resultado buscado: circuito de punta a punta client → hotelero → client, sin cuello
de botella de staff, con el catálogo público controlado por el client.

## El flujo, de punta a punta

```
INVITAR            ACEPTAR              COMPLETAR            HABILITAR           PUBLICAR
(client, portal)   (hotelero, PMS)      (hotelero, PMS)     (client, portal)    (consumo)
 name+email    ─▶   /invitacion/{token}  ─▶  checklist   ─▶   toggle          ─▶  API key /
 invitation         crea User+Account        completado       verified_at         widget / web
 (accom_id null)    +Accommodation           +quién/cuándo    +verified_by
                    pivote → ACTIVE
```

Visibilidad pública = **completo (hotelero) Y `verified_at` (client)**. Falta
cualquiera → no se muestra.

## Deltas respecto de `invitations.md`

| Sección spec | Antes | Ahora |
|---|---|---|
| §8 cola de encuadre | Staff deduplica y aprueba (pending→active) antes de que el client vea | **Se elimina.** Pivote → `active` al aceptar. Dedup: búsqueda del lado del client al invitar + herramienta de merge de staff **no bloqueante**, post-MVP |
| §10 decisión #3 | "El encuadre lleva revisión de staff" | Revertida: sin gate de staff |
| §5 esquema `invitations` | Sin `name` | + `name` (label que ve el client mientras está pendiente) |
| Publicación | "reclamado publica" / borrador no | Gate explícito: **`verified_at` del client**, solo habilitable si `completo` |
| Botón habilitar | Etiqueta por client (§5 verified) | **"Habilitar"** universal, sin diferenciar municipio/agencia; sin política auto per-client; **habilitar en lote** para escala |

Todo lo demás de la spec se mantiene: ramas del accept, regla del token, rechazo de
users `platform`/`client`, no reusar `/register`, un mail por persona, tenencia
desde el token.

## Trabajo, secuenciado

### 1. `api/` — camino crítico

**Migraciones**
- `invitations` (§5 de la spec) + campo **`name`** (nullable). Dos índices únicos
  parciales: `(client_id, email)` y `(client_id, accommodation_id)` pendientes.
- Pivote `accommodation_client`: `status enum('active','rejected') default 'active'`
  (ya **no** nace `pending` — sin gate de staff), `verified_at`,
  `verified_by_user_id`, `invitation_id`, timestamps.
- Completitud como **checklist** (no booleano `perfil_completo`) +
  `completed_by_user_id` / `completed_at`. V1: un checklist default hardcodeado, sin
  UI de edición. Deja lugar a fase 2 (documentos con vencimiento) sin construirla.

**Rutas — grupo `client-panel/v1`, `auth:sanctum`, tenencia desde `$request->user()->client_id`**
- `POST /invitations` — emitir (lote de emails). Dedup por `(client_id, email)`,
  validación de formato, rate-limit alto anti-abuso. Token hasheado server-side.
- `POST /invitations/{id}/resend` — invalida la anterior, emite nueva.
- `GET /invitations/{token}` — **público**. Devuelve el caso A/B/C y el **mínimo**
  (nombre del client, nombre del alojamiento si aplica). Nada de emails ni datos de
  cuenta.
- `POST /invitations/{token}/accept` — las ramas de §3 + guardas de §6. Al aceptar:
  crea/asocia, pivote → `active`, registra `accepted_by_user_id`. Fija `user_type` y
  `account_id` server-side; **no** reusar `POST /register`.
- `POST /invitations/{token}/decline` — solo caso C.
- `PATCH /associates/{id}/verify` y `.../unverify` — el **"Habilitar"** del client:
  setea/limpia `verified_at` + `verified_by_user_id`. Solo permitido si la ficha
  está `completo`. Soporta lote.
- `GET /associates` — el padrón con estado derivado (sin invitar / invitado /
  reclamado / completo / habilitado). Alimenta la pantalla del portal.
- `InvitationMail` + cola, **agrupado por casilla** (un mail, "tenés N propiedades").

### 2. `pms/` — aterrizaje del hotelero

- Ruta pública `/invitacion/{token}`. Header: **quién invita + qué hotel**.
- Cuatro desenlaces: **A** (form de alta → crea todo), **B** (login/alta → reclama
  stub), **C** (exige login como ese email → "¿sumarte al padrón de X?"), **rechazo**
  (email de user `platform`/`client`).
- Regla del token: A alcanza con token; B/C exigen **estar logueado como ese email**;
  logueado como otro → no asociar en silencio, ofrecer cambiar de sesión.
- Estados del token: vencido / ya aceptado / declinado / inválido, con mensaje claro.
- Post-accept → `/hotel` a completar; mostrar **propiedades hermanas pendientes**
  (un solo viaje). `/hotel` ya existe (General, Servicios, Políticas, Galería,
  Ubicación).

### 3. `crm/` — staff (reducido)

- Invitar / reenviar desde la ficha del alojamiento (equivalente `admin/` de emitir).
- **NO** cola de encuadre bloqueante (se eliminó). A futuro, herramienta de merge de
  duplicados **no bloqueante** (post-MVP).

### 4. `clients/` (portal) — consumo

- **Invitar por email** (name+email) con **búsqueda de dedup** antes de crear:
  mostrar alojamientos ya asociados a esa casilla; distinguir "mismo dueño, otra
  propiedad" (válido) de "ya invitaste ese nombre a esa casilla" (duplicado). Nunca
  devolver `[]` en `catch`; debounce; 60 req/min.
- **Estado del padrón**: completos / invitados sin aceptar / sin invitar, con fecha
  de último envío y **reenviar** (1 clic). Sale casi entera de `/associates`.
- **Toggle "Habilitar"** (+ habilitar en lote), habilitado solo sobre fichas
  completas. Única escritura del client; el resto sigue read-only.
- Consume vía `client-panel/v1` (tenencia del token, nunca `?client_id=`).

## Lo que NO entra (fase 2 / post-MVP)

Documentos con vencimiento (el esquema les deja lugar), curaduría rica (elegir qué
campos se muestran / reordenar), micrositio hosteado, widget embebible, tipos de
asociado más allá de `Accommodation`/`Tour`, política per-client de auto-habilitar.

## Verificación

Antes de implementar cualquier migración, verificar en `api/` el estado real de:
- el pivote `accommodation_client` (que siga "pelado", sin `status`);
- `routes/auth.php` cargado desde `web.php:52`;
- el endpoint público `/api/client/v1` de consumo.

La spec los documenta pero tienen fecha; confirmar antes de tocar el esquema.
