# Documents Plan — Bolsa de documentos por entidad, compartida con consentimiento

> Legajo digital de un prestador (hoy alojamiento; mañana restaurante y operador
> turístico): el prestador sube sus documentos **una vez**, y decide **con qué
> client los comparte y con cuál no**. Cada client ve solo lo que exige y lo que
> le compartieron — nunca el legajo completo.
> Fecha: 2026-07-21 · Stack: Laravel 9 / PHP 8.0 / Sanctum.
> Contexto: §2 y §7 de `.claude/skills/invitations.md` (esto es "la parte que hay
> que pensar ahora aunque sea fase 2"), pivote `accommodation_client` ya
> enriquecido con `verified_at` (ver `docs/` de invitaciones). Ver también
> `docs/api-clients-plan.md`.

## 1. El problema que resuelve

Un hotel acumula papeles: habilitación municipal, seguro de RC, certificado de
bomberos, inscripción en AFIP, etc. Tres clients distintos —municipio, cámara,
agencia— necesitan ver **subconjuntos distintos y solapados** de esos papeles.

Hoy no hay nada. La tentación es adjuntar el PDF a la relación
alojamiento↔client. Eso obliga a **resubir el mismo seguro** para el municipio y
para la cámara, y no le da al hotelero ningún control sobre qué muestra.

La inversión correcta: **el documento es del prestador, la exigencia es del
client, y compartir es un acto explícito del prestador.**

```
prestador sube al legajo ──▶ el legajo es privado ──▶ el prestador comparte
  (una vez, por tipo)          (nadie externo lo ve)     doc↔client (consentimiento)
                                                              │
                              client ve SOLO lo compartido ◀──┘
                              y su checklist le dice qué falta
```

## 2. Reglas que se fijan ahora (son gratis hoy, carísimas después)

Salen directo de §2/§7 de la skill de invitaciones. Aunque los vencimientos y la
verificación sean **fase 2**, el esquema del MVP tiene que dejarles lugar.

**Regla A — el documento es de la entidad; el requisito es del client.**
El hotel sube su habilitación una sola vez. Lo que cambia por client no es el
archivo: es el **checklist** de qué tipos de documento exige. Un booleano
`perfil_completo` en `accommodations` hay que tirarlo a la basura en la primera
venta a otra jurisdicción — el checklist es una tabla desde el día uno, aunque en
V1 haya un solo checklist default.

**Regla B — "verificado" es del vínculo, no del documento.**
El municipio marca *habilitado*, la cámara *socio al día*, la agencia *convenio
vigente*: misma forma, significados incompatibles. La verificación **fina** (este
seguro puntual está OK para este client) vive en el share; la verificación
**titular** ("habilitado") vive en el pivote `accommodation_client.verified_at`,
que ya existe. Nunca en `documents`.

**Regla C — compartir es explícito y revocable; el default es privado.**
Es la premisa que trae el usuario ("compartir o no") y es la correcta. Que una
agencia con convenio de comisiones vea la inscripción fiscal del hotel es un
problema real. El legajo **nace privado**; el hotelero comparte doc por doc,
client por client, y puede **des-compartir**. Nada se auto-comparte (mismo
principio que el consentimiento del pivote en §4 de la skill).

**Regla D — un archivo, muchos shares.**
Si dos clients exigen el mismo tipo, es **el mismo archivo**: una fila en
`documents`, N filas en `document_shares`. No se resube.

**Lo que un client NO ve nunca, en ningún estado** (heredado de la skill y del
aviso de `Admin\AccommodationResource` en `CLAUDE.md`): precios, disponibilidad,
habitaciones, reservas, y los datos internos de la cuenta (`bank_data`,
`tax_identification`, `file_number`, `comment`). El legajo es aparte de todo eso.

## 3. La síntesis: bolsa privada + checklist por client + share explícito

Tres piezas que juntas resuelven "compartir o no" sin construir un motor de
permisos:

1. **La bolsa (legajo).** Todos los documentos que subió la entidad. Privada.
   Cuelga de la entidad, no de la relación.
2. **El checklist (por client).** Qué **tipos** de documento exige cada client.
3. **El share.** El hotelero cumple un requisito adjuntando un doc de su bolsa a
   un client. `Compartir` = crear el share. `No compartir` = dejar el requisito
   sin cumplir. **La visibilidad de un client se define exclusivamente por la
   existencia de un share** → es imposible que un client vea algo fuera de su
   checklist, porque solo puede recibir shares de lo que él pidió.

Esto le da al hotelero control por `(documento, client)` **sin resubir**: el
mismo seguro cumple el requisito "seguro" del municipio **y** el de la cámara —
dos shares, un archivo.

## 4. Polimorfismo: el corazón del diseño (restaurante y operador vienen después)

El usuario ya avisó que después existirán **restaurante** y **operador
turístico**. Por eso el legajo **no puede colgar de `Accommodation`**: tiene que
ser polimórfico, exactamente como ya lo es `Image` en este repo
(`morphs('imageable')`, `Image::imageable()`).

- `documents.documentable_type` / `documentable_id` → hoy `Accommodation` (y
  también `Account`, ver abajo); mañana `Restaurant`, `TourOperator`, **sin tocar
  el esquema de documentos**.
- El `client` ya se relaciona con `accommodations` vía `accommodation_client`.
  Cuando lleguen las otras entidades tendrán su propio pivote, pero
  **`document_shares` referencia `document_id` (ya polimórfico) + `client_id`**,
  así que es agnóstico a la entidad desde el día uno.
- Los **tipos** de documento difieren por entidad (un operador no tiene
  habilitación bromatológica). Se resuelve con `document_types.entity_type`
  (nullable = universal) y `client_document_requirements.entity_type`. En V1 solo
  `accommodation`; la columna existe para no rehacer nada.

> Nota de dueño del documento: algunos tipos son naturalmente de la **cuenta**
> (inscripción fiscal / CUIT, que es de la empresa) y otros del **alojamiento**
> (habilitación municipal, que es por propiedad). El `documentable` polimórfico
> soporta ambos: un mismo legajo puede tener docs colgados de `Account` y de
> `Accommodation`. `document_types.owner_level` (`account` | `entity`) da la
> pista de dónde va cada uno.

## 5. Esquema

Nada de columnas de estado en `accommodations` que haya que mantener en sync. Los
tipos y checklists son **datos** (como `features`/`plan_feature`), no `if` por
slug en el código.

### `document_types` (catálogo, sembrado)

```
id
slug            unique   habilitacion_municipal | seguro_rc | certificado_bomberos | inscripcion_afip | ...
name
entity_type     nullable  accommodation | restaurant | tour_operator | NULL (universal)
owner_level     enum('account','entity') default 'entity'   dónde cuelga naturalmente
sort_order
is_active
timestamps
```

### `documents` (la bolsa — polimórfica, privada)

```
id
documentable_type / documentable_id   morphs()  → Accommodation | Account | (futuro) Restaurant, TourOperator
document_type_id   nullable FK          null = "otros"
title              string
url                string               el archivo vive en storage/bucket; acá va la referencia (como Image.url)
mime               nullable
size               nullable
uploaded_by_user_id FK → users
issued_at          nullable  ── FASE 2
expires_at         nullable  ── FASE 2 (vencimiento)
timestamps
softDeletes                             borrar un doc no debe romper el histórico de shares
```

### `client_document_requirements` (el checklist, por client)

```
id
client_id          FK → clients
entity_type        string default 'accommodation'   para checklists distintos por tipo de prestador
document_type_id   FK → document_types
required           boolean default true
notes              nullable
timestamps
UNIQUE (client_id, entity_type, document_type_id)
```

En V1: **un checklist default sembrado** para los clients existentes, sin UI de
edición (ver §10, decisión abierta). La tabla existe igual — Regla A.

### `document_shares` (el consentimiento / la visibilidad)

```
id
document_id          FK → documents        cascadeOnDelete
client_id            FK → clients          cascadeOnDelete
shared_by_user_id    FK → users            quién compartió (el hotelero)
shared_at            timestamp
verified_at          nullable  ── FASE 2   verificación FINA por (client, doc)  [Regla B]
verified_by_user_id  nullable  ── FASE 2
timestamps
UNIQUE (document_id, client_id)
```

**La existencia de la fila = está compartido.** Des-compartir = borrar la fila (o
`softDelete` si se quiere auditar). La verificación titular ("habilitado") sigue
en `accommodation_client.verified_at`, derivable de si todos los requisitos
`required` tienen un share (y en fase 2, un share verificado).

### Estados derivados (no se guardan)

| Estado del requisito | Condición |
|---|---|
| **faltante** | tipo `required` en el checklist, sin `document_share` para ese client |
| **compartido** | hay un `document_share` (doc de ese tipo) para ese client |
| **verificado** (fase 2) | el share tiene `verified_at` |
| **vencido** (fase 2) | el doc compartido tiene `expires_at < now()` |

## 6. Visibilidad y tenencia (las guardas)

- **Lado prestador (dueño):** ve y gestiona **toda su bolsa**. Reutiliza
  `AccommodationPolicy` (dueño por `account_id`) para los docs colgados del
  alojamiento, y una `AccountPolicy`/scope para los de la cuenta. Un `client` con
  acceso de solo lectura no escribe acá (middleware `client.readonly`).
- **Lado client:** ve un documento **si y solo si** existe `document_shares`
  para su `client_id`. El `Resource` público de documentos **parte de los shares
  del client**, nunca de la bolsa — es imposible enumerar el legajo. Misma
  filosofía que `Accommodation::scopeVisibleTo()`.
- La tenencia del client sale **del token** (`$request->user()->client_id`),
  nunca de la URL (§12 de la skill).

## 7. Los documentos NO son imágenes: almacenamiento privado

`Image` guarda una `url` pública y está bien: una foto del hotel es para
mostrarse. **Un documento fiscal/legal no.** Si el `documents.url` fuera un link
público de Cloudinary como el de las imágenes, "des-compartir" sería mentira: el
que ya vio la URL la tiene para siempre, y cualquiera que la adivine entra.

Decisión de diseño (no negociable para documentos):

- Los archivos van a un **bucket privado** (no world-readable), no a la carpeta
  pública de imágenes.
- El client no recibe una URL permanente: recibe el archivo por un **endpoint de
  la API que valida el share en cada request** (streaming/redirect a una **URL
  firmada de corta vida**). Sin share vigente → 403.
- Así el "des-compartir" es **revocación real e inmediata**.

En el MVP se puede empezar con URLs firmadas de vida corta generadas al vuelo; lo
que **no** se puede es reusar el pipeline público de `Image`.

## 8. Endpoints (siguiendo los grupos de ruta del repo)

### Catálogo
- `GET /api/admin/v1/document-types` — tipos (para armar checklists y clasificar).

### Lado prestador (su legajo) — PMS / account, bajo `auth:sanctum` + policy de dueño
- `GET    …/accommodations/{accommodation}/documents` — la bolsa completa (dueño).
- `POST   …/accommodations/{accommodation}/documents` — sube al legajo (body: `document_type_id`, `title`, `url`/upload).
- `DELETE …/accommodations/{accommodation}/documents/{document}`.
- `GET    …/accommodations/{accommodation}/sharing` — matriz: por client, qué exige (checklist) y qué está compartido/faltante.
- `POST   …/accommodations/{accommodation}/documents/{document}/shares` — compartir con un client (body: `client_id`).
- `DELETE …/accommodations/{accommodation}/documents/{document}/shares/{client}` — des-compartir.
- `GET    …/accommodations/{accommodation}/documents/{document}/download` — descarga (dueño).

### Lado client — `/api/client-panel/v1` (token = tenencia)
- `GET  …/associates/{accommodation}/documents` — **solo lo compartido**, con estado de checklist (cumplido/faltante).
- `GET  …/document-requirements` — el checklist de este client (qué exige).
- `GET  …/associates/{accommodation}/documents/{document}/download` — descarga, valida share (§7).
- Fase 2: `POST …/associates/{accommodation}/documents/{document}/verify`.

## 9. Fases

### F1 — MVP ✅ IMPLEMENTADA (lado `api/`, 2026-07-21)

> Migraciones corridas y seeders sembrados en el container. Falta cablear los
> frontends (PMS sube al legajo / comparte; portal del client ve lo compartido).


1. Migraciones: `document_types`, `documents` (polimórfica), `client_document_requirements`, `document_shares`. Sin las columnas fase 2 marcadas arriba salvo que sean baratas de dejar nullable (recomendado dejarlas nullable ya).
2. Seeders: catálogo `document_types` (idempotente por slug, estilo `FeatureSeeder`) + un checklist default por client existente.
3. Modelos: `Document` (morphTo `documentable`, `belongsTo documentType`, `hasMany shares`), `DocumentType`, `DocumentShare`, `ClientDocumentRequirement`. En `Accommodation` y `Account`: `morphMany documents`.
4. Repos + interfaces + binding (patrón del repo). `DocumentInterface`/`DocumentRepository`.
5. Endpoints del prestador (subir, borrar, compartir, des-compartir, matriz de sharing).
6. Endpoints del client (leer solo lo compartido + checklist + descarga validada).
7. Almacenamiento privado + descarga con validación de share (§7).
8. Resources: `DocumentResource` (dueño, legajo completo) y `SharedDocumentResource` (client, recortado, parte de los shares).

### F2 — Deja lugar el esquema, no se construye ahora
- **Vencimientos** (`issued_at`/`expires_at`) + alertas de por-vencer.
- **Verificación fina** (`document_shares.verified_at`) y su rollup a
  `accommodation_client.verified_at` ("habilitado").
- **Editor de checklist** por client (UI en el CRM/portal).
- **Restaurante y operador turístico**: la capa de documentos ya los soporta por
  polimorfismo — solo hay que crear las entidades y su pivote con `client`, y
  sembrar sus `document_types`. Ese es el pago de haber hecho §4 ahora.

## 10. Decisiones abiertas (no bloquean arrancar)

1. **Granularidad del share.** Recomendado: `document_shares(document_id,
   client_id)` — el requisito se deriva por `document.document_type_id`.
   Alternativa (más fina, innecesaria en MVP): agregar `document_type_id` al
   share para "llenar un casillero" puntual cuando el hotel tiene varios docs del
   mismo tipo.
2. **¿Checklist editable en V1 o solo default sembrado?** Recomendado lo segundo:
   tabla + un checklist default hardcodeado, sin UI (mismo criterio que la skill).
3. **Borrar vs `softDelete` en `document_shares`.** Si se quiere auditar
   "estuvo compartido y se revocó", `softDelete`. Para MVP, borrado duro alcanza.
4. **Bucket concreto** (S3 privado vs Cloudinary con entrega autenticada). No
   cambia el esquema; sí cambia el adaptador de descarga.

## 11. Lo que NO entra

- Firma electrónica / validez legal de los documentos — no es esto.
- OCR / extracción de datos del PDF — fuera de alcance.
- Compartir con terceros que **no** sean `client` del sistema — un documento solo
  se comparte con clients existentes, nunca con un email suelto.
- Un modelo genérico "prestador" del lado del código antes de tiempo: la API
  modela `Accommodation` (y luego `Restaurant`, `TourOperator`) como entidades
  concretas; el polimorfismo vive en `documents`, no en una tabla `providers`.
