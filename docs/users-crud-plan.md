# CRUD de Users — `/api/admin/v1/users`

Contrato para implementar la gestión de usuarios desde el CRM de plataforma
(`../crm`). El frontend ya está construido contra esta forma: cambiarla implica
cambiar `crm/lib/crm/users.ts` y `crm/app/(protected)/users/page.tsx`.

## Por qué

Hoy la API **no expone ninguna ruta de users** (verificado: no hay entradas de
`users` en `routes/api.php` fuera de `/api/user`, que es el perfil propio). Eso
deja al CRM sin forma de dar de alta staff de plataforma, usuarios de clients
B2B ni usuarios de cuentas hoteleras: hoy se hace a mano por tinker.

## Lo crítico: tenencia

Esto no es un CRUD más. `user_type` + `account_id` + `client_id` son el control
de acceso de toda la API, no metadata.

Fuentes de verdad ya existentes, leer antes de escribir código:

| Archivo | Qué define |
|---|---|
| `database/migrations/2026_07_07_000002_add_tenancy_to_users_table.php` | `user_type` es `string(20)` con default `'account'`; `account_id` y `client_id` nullable |
| `app/Models/User.php` | `TYPE_PLATFORM` / `TYPE_ACCOUNT` / `TYPE_CLIENT` + `isPlatform()` / `isAccount()` / `isClient()` |
| `app/Models/Accommodation.php::scopeVisibleTo` | Cómo la tenencia se traduce en visibilidad, **fail-closed** |
| `app/Policies/AccommodationPolicy.php` | Patrón `before()` con bypass para platform |
| `app/Console/Commands/BackfillUserTenancy.php` | Convención: los `platform` van con `account_id` y `client_id` en `NULL` |
| `app/Http/Middleware/EnsurePlatform.php` | El middleware que protege `/api/admin/v1` |

### Invariantes que el CRUD debe garantizar

1. `user_type = 'platform'` ⇒ `account_id === null` **y** `client_id === null`.
   Un platform bypassa la tenencia; dejarle un `account_id` colgado es estado
   inconsistente que puede filtrarse a otras queries.
2. `user_type = 'account'` ⇒ `account_id` obligatorio, `client_id` null.
3. `user_type = 'client'` ⇒ `client_id` obligatorio, `account_id` null.
4. **Solo un `platform` puede asignar o cambiar tenencia arbitraria.** Nadie
   puede auto-promoverse: un usuario no-platform no puede setear
   `user_type = 'platform'` ni moverse a otro `account_id`.
5. `account_id` y `client_id` deben existir (`exists:accounts,id`,
   `exists:clients,id`). Un id inválido no puede persistirse.

Un `account_id` mal asignado le da al usuario visibilidad sobre los alojamientos
de otra cuenta. Es la falla de seguridad más probable de esta feature, y no la
detecta ningún test de "el endpoint responde 200".

## Endpoints

Todos bajo el grupo `admin/v1` existente en `routes/api.php`, detrás de
`auth:sanctum` + middleware `platform`. Seguir el estilo de
`app/Http/Controllers/Api/Admin/V1/ClientController.php`.

### `GET users`

Filtros (todos opcionales): `search` (matchea `name` **o** `email`),
`user_type`, `account_id`, `client_id`, `page`, `limit` (default 15, como
`AccommodationController::index`).

Respuesta: colección paginada, misma forma que
`AccommodationResourceCollection` — **no declarar `meta` propio**, dejar que
Laravel arme el bloque de paginación (ver el comentario en ese archivo: las
claves duplicadas se fusionan en arrays).

### `POST users`

```json
{
  "name": "string, requerido",
  "email": "string, requerido, email, unique:users",
  "password": "string, requerido, min 8, confirmed",
  "user_type": "platform|account|client, requerido",
  "account_id": "requerido si user_type=account, si no null",
  "client_id":  "requerido si user_type=client, si no null"
}
```

Responde `201` con `UserResource`.

### `GET users/{id}` · `PATCH users/{id}` · `DELETE users/{id}`

`PATCH` acepta los mismos campos con `sometimes`. `password` opcional; si viene
vacío no se toca. Al cambiar `user_type` hay que **recalcular la tenencia** para
no dejar los ids del tipo anterior.

### `UserResource`

```
id, name, email, user_type, account_id, client_id, created_at
```

**Nunca** exponer `password`, `remember_token` ni tokens de Sanctum.

## Policy

`UserPolicy` con `before()` que devuelve `true` para platform, siguiendo
`AccommodationPolicy`. Como el grupo entero ya está detrás del middleware
`platform`, la policy es defensa en profundidad — pero hay que escribirla igual,
porque el middleware protege la ruta y la policy protege el modelo.

Casos a cubrir explícitamente:

- Un usuario no puede eliminarse a sí mismo (deja la plataforma sin staff si es
  el último).
- Evaluar si hay que impedir eliminar el **último** usuario platform.

## Tests

En `tests/`, cubriendo la tenencia cruzada — que es lo que importa:

- Un `account` no puede crear ni listar users (403 por el middleware).
- Un `client` tampoco.
- Crear un `platform` deja `account_id` y `client_id` en null.
- Crear un `account` sin `account_id` falla con 422.
- Crear un `account` con un `account_id` inexistente falla con 422.
- Cambiar `user_type` de `account` a `platform` limpia `account_id`.
- El resource no filtra `password` ni tokens.

## Decisiones abiertas

Resolver **antes** de implementar, no sobre la marcha:

1. **Borrado**: ¿`DELETE` es baja física, soft delete, o un flag de
   desactivación? Un usuario borrado con reservas asociadas puede romper
   relaciones. La opción conservadora es desactivar.
2. **Tokens de Sanctum al desactivar/eliminar**: los tokens vigentes siguen
   funcionando hasta expirar salvo que se revoquen explícitamente. Si el punto
   de "dar de baja a alguien" es cortarle el acceso, hay que borrarle los
   tokens; si no, la baja es cosmética durante la vida del token.
3. **Cambio de `user_type` en caliente**: ¿se permite? Mover un usuario entre
   tipos cambia qué datos ve. Como mínimo debería quedar auditado.
4. **Alta de contraseña**: ¿la setea el staff, o se manda un mail de invitación?
   Si es lo segundo hace falta el flujo de invitación, que es scope aparte.

## Convenciones

- Sin `any` (aplica al front; en PHP, tipos declarados donde el proyecto ya los usa).
- `./vendor/bin/pint` al final.
- No correr migraciones destructivas: la base de dev tiene datos reales
  (76 alojamientos, clients con keys emitidas).
