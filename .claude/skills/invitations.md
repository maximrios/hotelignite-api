# Invitaciones — especificación para `../api`

Spec del flujo de invitación y alta de asociados. Escrito para que lo implemente
quien trabaje en `../api`; el CRM, el PMS y este portal consumen el resultado.

Reemplaza la premisa anterior de la skill ("el alta la hace el staff, el client
no invita"). El cambio es deliberado: **el que conoce a los alojamientos es el
client**, no el staff. Un municipio tiene el padrón; nosotros no.

---

## 1. El problema que resuelve

Hoy un alojamiento entra al sistema solo si el staff lo carga a mano. Eso no
escala: cuando un municipio llega con 200 prestadores, el cuello de botella es
nuestro, y encima el dato lo tiene el municipio.

La inversión es: **el client invita, el hotelero se da de alta y completa, el
client habilita.** No hay paso de staff (ver §8).

```
client invita ──▶ hotelero se registra ──▶ hotelero completa ──▶ client habilita ──▶ público
  (por email)      (crea Account +          (checklist)           (verified_at)      lo ve
                    Accommodation,
                    pivote → active)
```

---

## 2. Los tres clients, y por qué importan al modelar

No son variantes cosméticas: cada uno usa el padrón para algo distinto, y eso
decide qué va en el pivote y qué va en el alojamiento.

| Client | Qué es el padrón para él | Palanca sobre el asociado | Documentos |
|---|---|---|---|
| **Municipio / ente** | Registro de habilitados | Regulatoria — fuerte | Sí, con vencimiento |
| **Cámara hotelera** | Listado de socios | Membresía — media | Sí, distintos |
| **Agencia con convenios** | Hoteles con comisión | Ninguna | Normalmente no |

De acá salen dos reglas que conviene fijar ahora:

**Regla A — el documento es del alojamiento; el requisito es del client.**
El hotel sube su habilitación **una sola vez**. La cámara ve lo que ya subió, y
si le falta algo, lo pide. Lo que cambia por client no es el archivo: es el
**checklist** de qué archivos exige. Modelalo así desde el día uno aunque en V1
haya un solo checklist por defecto — un booleano `perfil_completo` hay que
tirarlo a la basura en la primera venta a otra jurisdicción.

**Regla B — "verificado" es del pivote, no del alojamiento.**
El municipio marca *habilitado*, la cámara marca *socio al día*, la agencia marca
*convenio vigente*. Misma forma, significados incompatibles. Si lo ponés como
columna en `accommodations`, el primer alojamiento que esté en dos padrones te
obliga a rehacerlo.

> Los documentos con vencimiento son **fase 2**, no MVP. Pero estas dos reglas
> son gratis hoy y carísimas después, así que el esquema del MVP tiene que
> dejarles lugar.

---

## 3. Una tabla, tres desenlaces

La invitación **siempre es a un email**. Lo que pasa al aceptar depende del
estado del mundo en ese momento, no del tipo de invitación.

| Estado al aceptar | Qué hace el accept |
|---|---|
| **A** — no hay nada | Crea `User` + `Account` + `Accommodation`, pivote → `active` |
| **B** — hay un borrador (stub del staff) | Lo reclama: setea `account_id`, pivote → `active` |
| **C** — el alojamiento ya está reclamado | **No crea nada**: pide sumarlo al pivote del client → consentimiento |

El caso **C** es tu pregunta de los dos clients, y abajo tiene sección propia
porque tu premisa ahí no cierra.

`accommodation_id` es **nullable**: cuando el client invita a un hotel que no
existe en la base todavía (el caso normal), no hay a qué apuntar. Cuando el
staff invita sobre un stub que ya cargó, sí.

---

## 4. El caso de los dos clients — tu premisa está mal

Escribiste:

> ¿qué pasa si un client invita a uno y luego otro client invita al mismo? en ese
> caso el email ya existe, la invite ya deprecó pues ya se aceptó la primera en
> llegar

**No.** Que un hotel esté en el padrón del municipio *y* en el de la cámara *y*
en el de la agencia no es un conflicto: es el caso normal. Un hotel de Salta está
habilitado por el municipio, es socio de la cámara y tiene convenio con tres
agencias — las cuatro cosas a la vez, todo el tiempo.

`accommodation_client` es **muchos a muchos** justamente por eso. La segunda
invitación no se deprecia: **se resuelve distinto**. El hotelero hace clic,
ya tiene cuenta, y en vez de un formulario de registro ve:

> *La Cámara Hotelera de Salta quiere sumar **Hotel Los Cardones** a su padrón.*
> [ Aceptar ] [ Rechazar ]

Lo único que se invalida al aceptar es **otra invitación pendiente del mismo
client para el mismo email**. Nunca las de otro client.

### Y esto necesita consentimiento, no auto-vínculo

Es tentador que el caso C vincule solo. No lo hagas:

- **La agencia implica comisiones.** Aparecer en el listado de una agencia con la
  que no tenés convenio es un problema comercial del hotelero, no nuestro.
- **Los documentos se comparten.** Si sumar al pivote diera acceso automático al
  legajo, cualquier client que sepa un email vería la documentación fiscal de un
  hotel. Ver §7.

El default del MVP: **en el caso C no hay pivote hasta que el hotelero consiente.**
Al aceptar el consentimiento se crea el vínculo ya en `active` (el consentimiento
*es* la aprobación; no lo confundas con el gate de staff que se eliminó, §8). Barato
ahora, imposible de retrofitear después de la primera queja. Ojo: esto es el
consentimiento del hotelero para el caso C, distinto del "Habilitar" del client que
gatea la publicación (§8).

---

## 5. Esquema

### `invitations`

```
id
client_id             FK → clients          quién invita (null si invita el staff)
accommodation_id      FK → accommodations   NULLABLE — solo en casos B y C
name                  nullable string       label que el client tipeó; lo ve mientras está pendiente
email                 string
token_hash            unique
expires_at                                  30 días
accepted_at           nullable
accepted_by_user_id   nullable FK → users   quién reclamó realmente
declined_at           nullable              el caso C se puede rechazar
created_by_user_id    FK → users
timestamps
```

**Índices únicos parciales** (cambia respecto de la versión anterior de la skill,
que indexaba solo por `accommodation_id` — eso asumía un único invitador):

```
UNIQUE (client_id, email) WHERE accepted_at IS NULL AND declined_at IS NULL
UNIQUE (client_id, accommodation_id) WHERE accommodation_id IS NOT NULL
                                       AND accepted_at IS NULL AND declined_at IS NULL
```

Dos clients pueden tener una invitación pendiente para el mismo email o el mismo
alojamiento al mismo tiempo. **Es legítimo.** Un mismo client, no.

**30 días de vencimiento.** Siete es lo habitual y es corto para un padrón
municipal, donde la gente tarda semanas. El costo de acortarlo son reenvíos, y el
reenvío tiene que ser de un clic.

### `accommodation_client` (el pivote, hoy pelado)

```
+ status              enum('active','rejected')  default 'active'   (nace 'active': sin gate de staff, §8)
+ verified_at         nullable      el "Habilitar" del client — gate de publicación (§8)
+ verified_by_user_id nullable FK → users
+ invitation_id       nullable FK → invitations    trazabilidad del origen
+ timestamps
```

`verified_at` es la Regla B y **el único gate de publicación** (§8). El botón dice
"Habilitar" y nada más; la semántica ("habilitado", "socio al día", "convenio
vigente") la sabe el client, no la UI.

### Los estados se derivan, no se guardan

Nada de una columna `status` en `accommodations` que haya que mantener en sync:

| Estado | Condición |
|---|---|
| **borrador** | `account_id IS NULL`, sin invitación pendiente |
| **invitado** | `account_id IS NULL`, hay invitación pendiente |
| **reclamado** | `account_id IS NOT NULL` |
| **completo** | `reclamado` y la ficha pasó el checklist (§ completitud) |
| **habilitado** | `completo` y el pivote tiene `verified_at` (el "Habilitar" del client) |

---

## 6. Dónde aterriza el email — **PMS, no CRM**

Dijiste que el link lleva al CRM. **Cambialo.** El CRM es el panel interno del
staff: todo el bloque está detrás del middleware `platform`, y `UserPolicy` exige
`isPlatform()` en los cinco métodos. Un hotelero ahí adentro no tiene nada que
hacer, y abrirle una puerta al CRM es abrirle una puerta al panel que administra
privilegios.

El destino correcto es el **PMS** (`../pms`), y el argumento es que **la
superficie de destino ya existe**: `/hotel` tiene General, Servicios, Políticas,
Galería y Ubicación, con subida a Cloudinary. El hotelero termina de registrarse
y está parado exactamente donde tiene que completar la ficha.

```
mail ──▶ /invitacion/{token} en el PMS (ruta pública, sin auth)
             │
             ├─ caso A ─▶ formulario de alta ─▶ crea User+Account+Accommodation ─▶ /hotel
             ├─ caso B ─▶ login o alta ────────▶ reclama el borrador ───────────▶ /hotel
             └─ caso C ─▶ exige login como ese email ─▶ "¿sumarte al padrón de X?"
```

### La regla del token

> **El token prueba que recibiste el mail en esa casilla. Nada más.**

- **Caso A**: el token alcanza. El user que se crea *es* el de ese email, así que
  el token oficia de verificación.
- **Casos B y C**: hay que **estar logueado como ese email**. Si estás logueado
  como otro, no asocies en silencio — mostrá para quién es la invitación y ofrecé
  cambiar de sesión.

Sin esto, quien tenga el link decide a qué `Account` va a parar el alojamiento.
Un mail reenviado y el hotel aterriza en la cuenta equivocada; revertirlo
requiere staff. El caso es real en cadenas y administradores de varias
propiedades, que es justo donde el error sale más caro.

### El rechazo que falta

Si el email pertenece a un user `platform` o `client` (un empleado del municipio
que además tiene una cabaña), la tentación es "convertirlo" a `account`. **No.**
Cambiarle el `user_type` a un `platform`, o darle `account_id` a un `client`, es
un desastre de privilegios. Rechazo explícito con un mensaje que diga qué hacer.

### No reutilizar `POST /register`

`routes/auth.php` se carga desde `web.php:52`, está bajo `guest`, y
`RegisteredUserController::store()` hace `User::create()` sin pasar `user_type` —
cae en el default `'account'`. El accept necesita fijar `user_type` y
`account_id` **server-side desde la invitación**. Endpoint aparte.

---

## 7. Documentos compartidos — la parte que hay que pensar ahora aunque sea fase 2

Tu intuición es correcta: **el hotel sube una vez, todos los clients ven.**
El documento cuelga de la `Account` / `Accommodation`, no de la relación.

Pero "todos ven" no puede ser literal. El hotel subió su habilitación municipal
*para el municipio*; que una agencia con la que tiene convenio de comisiones vea
su inscripción fiscal es otra cosa.

El mínimo viable, sin construir un sistema de permisos:

- El **checklist es por client** — cada uno declara qué exige.
- El client ve **solo los documentos de su checklist**, no el legajo completo.
- Si dos clients exigen el mismo documento, es el mismo archivo. No se resube.

Así la cámara ve que el hotel ya tiene bomberos y seguro (porque los pidió el
municipio), pide lo que le falta, y nunca ve nada que no haya declarado necesitar.

**Nunca es del client, en ningún estado:** precios, disponibilidad, habitaciones,
reservas del hotelero, y los datos internos de la cuenta (`bank_data`,
`tax_identification`, `file_number`, `comment` — ver el aviso sobre
`Admin\AccommodationResource` en `CLAUDE.md`).

---

## 8. Publicación: el gate es del client, no del staff

> **Cambio respecto de la versión anterior de esta spec.** Antes había una "cola de
> encuadre" del staff que bloqueaba la publicación (pivote `pending` → `active`
> aprobado por staff). **Se eliminó.** Motivo: la relación con el asociado es del
> client, no del staff, y el gate de staff hacía a la plataforma cuello de botella
> (200 prestadores = cola de 200). Ver `../docs/invitations-plan.md`.

**El pivote nace `active` al aceptar.** No hay paso de aprobación de staff.

El único gate de publicación es el **"Habilitar" manual del client** — que en el
esquema es `verified_at` (Regla B, §5). El client decide, caso por caso, qué
asociado entra a su padrón público, en base a algo que el sistema no conoce (llegó
la habilitación municipal, se firmó el convenio). Reglas:

1. **"Habilitar" es universal.** El botón dice "Habilitar" y nada más — no se
   diferencia municipio de agencia en la UI; la semántica (habilitación vs convenio)
   la sabe el client. Sin política per-client de auto-habilitar.
2. **Solo se habilita lo completo.** El toggle se enciende únicamente si la ficha
   pasó el checklist (§ completitud). Habilitar una ficha pobre es lo que el gate
   evita.
3. **Habilitar en lote**, para que un municipio con 200 asociados no dé 200 clics.

Visibilidad pública = **completo (hotelero) Y `verified_at` (client)**. Falta
cualquiera → no se muestra.

**La `Account` se crea al aceptar** (no al habilitar). Está bien porque **el plan
default es free** (decisión tomada) — si fuera pago, cada aceptación facturaría
desde el minuto cero.

### Deduplicación — sin gate bloqueante

El dedup ("Hotel Los Cardones" vs "Cardones Hotel & Spa") sigue importando, pero
**ya no bloquea**. Se cubre en dos capas:

- **Al invitar (client, preventivo):** búsqueda antes de crear. Se le muestran al
  client los alojamientos ya asociados a esa casilla, distinguiendo "mismo dueño,
  otra propiedad" (válido) de "ya invitaste ese nombre a esa casilla" (duplicado).
- **Post-MVP (staff, correctivo):** una herramienta de merge de duplicados **no
  bloqueante**. Fusiona registros ya creados sin frenar la publicación.

---

## 9. El email sale de `../api`

Sin dudas. No desde este portal ni desde el CRM:

- **El token se genera y se hashea server-side.** Si el mail lo arma Next, el
  token crudo viaja a un frontend. No hay razón para que eso pase.
- **Lo disparan tres orígenes** — el client desde este portal, el staff desde el
  CRM, y el reenvío automático. Si vive en un frontend, hay que escribirlo dos
  veces y va a divergir.
- **Laravel ya tiene lo que hace falta**: Mailable, cola, reintentos, plantillas.
  Rebotes y reintentos en un route handler de Next es reinventar mal.

Los frontends llaman a `POST client-panel/v1/invitations` (o el equivalente
`admin/` para el staff) y no ven nunca el token.

### Un mail por persona, aunque sean varias filas

Una invitación por alojamiento en la tabla, pero **un solo mail por casilla**.
Tres mails separados a la misma persona bajan la aceptación, y la aceptación es
la métrica que gatea todo el producto. Un mail que diga "tenés 3 propiedades para
reclamar", y después de crear la cuenta el flujo va mostrando las que quedan.

No cambia el esquema, solo cómo se agrupa al notificar.

---

## 10. Qué falta para arrancar

En orden. Nada de esto existe: cero coincidencias de `invit` en `app/` y en
migraciones de `../api`.

### En `../api` — el camino crítico

| # | Qué | Detalle |
|---|-----|---------|
| 1 | Migración `invitations` | §5, con `name` y los dos índices parciales |
| 2 | Migración del pivote | `status` (default `active`), `verified_at`, `verified_by_user_id`, `invitation_id` |
| 3 | Grupo `client-panel/v1` | `auth:sanctum`, tenencia desde `$request->user()->client_id` |
| 4 | `POST client-panel/v1/invitations` | Emitir. Acepta lote de emails |
| 5 | `POST client-panel/v1/invitations/{id}/resend` | Invalida la anterior, emite nueva |
| 6 | `GET /invitations/{token}` | **Público.** Devuelve el caso (A/B/C) sin filtrar datos |
| 7 | `POST /invitations/{token}/accept` | Las tres ramas de §3 + las guardas de §6. Pivote → `active` |
| 8 | `POST /invitations/{token}/decline` | Solo caso C |
| 9 | `InvitationMail` + cola | Agrupado por casilla (§9) |
| 10 | `GET client-panel/v1/associates` | El padrón con estado derivado — alimenta la pantalla del portal |
| 11 | `PATCH client-panel/v1/associates/{id}/verify` · `.../unverify` | El "Habilitar" del client (§8). Solo si `completo`. Soporta lote |

El **6** tiene una trampa: es público y devuelve datos de un alojamiento a quien
tenga el token. Devolvé el mínimo — nombre del client que invita, nombre del
alojamiento si aplica, y nada más. Ni emails, ni datos de cuenta.

### Después, en los frontends

| Dónde | Qué |
|---|-----|
| `../pms` | `/invitacion/{token}` — las tres ramas, ruta pública |
| `../crm` | Invitar/reenviar desde la ficha (sin cola de encuadre — se eliminó, §8) |
| **acá** | Invitar por email + estado del padrón (completos / invitados / sin invitar) + toggle "Habilitar" |

### Decisiones ya tomadas

1. **El client invita sin cupo.** El objetivo es incorporar la mayor cantidad de
   alojamientos posible, así que no ponemos límite. **Dos guardas siguen valiendo
   aunque no haya cupo**, porque el riesgo no es el volumen sino el abuso: el
   remitente es nuestro dominio, y un pico de invitaciones a direcciones basura
   nos quema la reputación de envío. Mínimo: dedupe de invitación pendiente por
   `(client_id, email)` (ya está en el índice) y validación de formato de email
   antes de encolar el mail. Rate-limit sí, pero alto y solo anti-abuso, no como
   tope de producto.
2. **El plan default de la `Account` es free.** Ver §8 — es lo que hace seguro
   crear la cuenta al aceptar.
3. **Sin gate de staff.** El pivote nace `active` al aceptar. El único gate de
   publicación es el "Habilitar" manual del client (`verified_at`), solo sobre
   fichas completas. Ver §8. (Revierte la decisión anterior de "encuadre por staff".)

### Decisiones que siguen abiertas (no bloquean arrancar)

- **¿El checklist se implementa en el MVP o solo se deja el lugar?** Recomiendo lo
  segundo: la tabla y un checklist default hardcodeado, sin UI de edición.
- **¿Quién puede invitar dentro del client?** Hoy no hay roles adentro de un
  client. Si hay un solo usuario por client, no es problema todavía.

---

## 11. Lo que NO entra

- **Documentos con vencimiento** — fase 2. El esquema les deja lugar (§2, §7),
  nada más.
- **Curaduría** (elegir qué se muestra y cómo) — es el único paso que no bloquea
  a ningún otro. Si hay que recortar, se recorta este.
- **Tipos de asociado más allá de alojamientos** — la API modela `Accommodation`
  y `Tour`. No inventes un modelo genérico "asociado" del lado de Next. El
  vocabulario de la UI sí puede adelantarse ("asociados", "prestadores"); el
  modelo no.
- **Micrositio hosteado** — el más vendible y el más caro. No es MVP.

---

## 12. La regla que no se negocia

La tenencia sale **del token, nunca de la URL ni de un filtro del frontend**.
Todo endpoint nuevo va en `client-panel/v1` con `auth:sanctum` leyendo
`$request->user()->client_id`.

Si aparece un `?client_id=` en una URL de este portal, algo se diseñó mal.
