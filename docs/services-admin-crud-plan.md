# CRUD de Services — `/api/admin/v1/services`

Contrato para completar la gestión del catálogo de servicios desde el CRM de
plataforma (`../crm`). El frontend **ya está construido** contra esta forma
(`crm/lib/crm/services.ts`, `crm/app/(protected)/settings/services/`): la lectura
funciona hoy, la escritura devuelve 404 hasta que se implemente esto.

## Estado actual

`routes/api.php` registra bajo `admin/v1` **solo la lectura**:

```php
Route::get('services', [ServiceController::class, 'index'])->name('admin.services.index');
Route::get('services/{service}', [ServiceController::class, 'show'])->name('admin.services.show');
```

`App\Http\Controllers\Api\Admin\V1\ServiceController` **ya tiene** `store()`,
`update()` y `destroy()`, y están ruteados — pero bajo `/api/v1/services`
(líneas ~241-245), que no es lo que consume el CRM. Falta exponerlos en
`admin/v1`, y antes de eso hay tres bugs que arreglar.

## Lo crítico: el Resource miente sobre el esquema

Esto es lo primero a resolver; lo demás depende de cómo se decida acá.

La tabla real (verificada contra la base `hotelignite`, no contra las
migraciones) es:

```
id              int auto_increment
name            varchar(255) NOT NULL DEFAULT '0'
ico             varchar(255) NOT NULL DEFAULT '0'
enabled         tinyint      NOT NULL DEFAULT 0
type            enum('general','room','bathroom','accessibility','kitchen') NOT NULL DEFAULT 'general'
is_highlighted  tinyint(1)   NOT NULL DEFAULT 0
```

No hay `slug`. No hay `icon`. No hay `created_at` / `updated_at`.

Contra eso, hoy:

| Lugar | Qué dice | Realidad |
|---|---|---|
| `Service::$fillable` | `slug`, `icon`, `created_at`, `updated_at` | ninguna de las 4 columnas existe |
| `ServiceResource` | expone `slug` e `icon` | ambos vuelven siempre `null` |
| `StoreServiceRequest` | `'slug' => [..., 'unique:services,slug']` | la regla `unique` corre un `SELECT ... WHERE slug = ?` sobre una columna inexistente |
| `UpdateServiceRequest` | ídem | ídem |
| `Service` | sin `$casts` | `enabled` e `is_highlighted` salen como `1`/`0`, no `true`/`false` |

Consecuencia práctica: **un POST a `/api/v1/services` con `slug` hoy tira un
SQL 1054** (por la regla `unique`), y uno con `icon` también (`Service::create()`
usa `$request->all()`, así que `icon` pasa el filtro de `$fillable` y llega al
INSERT). Es decir que el `store` que ya existe está roto para dos de los campos
que él mismo declara.

### Decisiones pedidas

1. **`slug`**: sacarlo de `$fillable`, del `ServiceResource` y de los dos
   FormRequests. No hay caso de uso: el CRM no lo usa y nada lo consulta.
   (Alternativa: agregar la columna con una migración + backfill desde `name`.
   Solo si algo de `../pms` o `../clients` la necesita — no lo verifiqué.)
2. **`icon`**: mantener el nombre `icon` en la API y mapearlo a la columna `ico`,
   porque `ico` no se puede renombrar sin tocar el dump legacy. Lo más barato:

   ```php
   // App\Models\Service
   protected $fillable = ['name', 'ico', 'enabled', 'type', 'is_highlighted'];

   public function icon(): Attribute
   {
       return Attribute::make(
           get: fn () => $this->ico ?: null,
           set: fn (?string $v) => ['ico' => $v ?? ''],
       );
   }
   ```

   Ojo con el default: `ico` es `NOT NULL DEFAULT '0'` — las 7 filas actuales
   guardan HTML crudo de Font Awesome (`<i class="fa fa-snowflake-o"></i>`).
   El CRM manda `icon: null` cuando el campo está vacío, así que el setter tiene
   que traducirlo a `''` y no dejar pasar el `null` a una columna NOT NULL.
3. **Casts**: agregar `protected $casts = ['enabled' => 'boolean',
   'is_highlighted' => 'boolean']`. El CRM ya normaliza con un helper (`flag()`)
   porque hoy llegan como número, pero eso es un parche del lado del cliente.
4. **Timestamps**: `public $timestamps = false;` en el modelo. Hoy no está y las
   columnas no existen; si algún día se llama a `Service::create()` con
   timestamps activos, falla.

## Rutas a agregar

Dentro del grupo `Route::middleware('platform')` de `admin/v1` (línea ~163) —
**no** en el tramo de catálogos abiertos donde están `index`/`show` hoy. El
`index` y el `show` se quedan donde están: los necesita cualquiera que edite un
alojamiento. Escribir el catálogo global, en cambio, es staff de plataforma.

```php
Route::post('services', [ServiceController::class, 'store'])->name('admin.services.store');
Route::match(['put', 'patch'], 'services/{service}', [ServiceController::class, 'update'])
    ->name('admin.services.update');
Route::delete('services/{service}', [ServiceController::class, 'destroy'])
    ->name('admin.services.destroy');
```

El CRM usa **PATCH** para editar; el `PUT` queda por compatibilidad con lo que ya
existe en `/api/v1`.

### El `destroy` tiene que cambiar de forma

Hoy es `DELETE /services` con `service_id` **en el cuerpo**
(`DestroyServiceRequest`). Eso no coincide con ningún otro DELETE de `admin/v1`
(`users`, `accounts`, `accommodations` usan todos el id en la ruta) y el CRM
llama a `DELETE /services/{id}`. Cambiar la firma en `admin/v1` a route-model
binding. El `/api/v1` legacy puede quedarse como está si algo lo consume.

## Contratos

### `GET admin/v1/services`

Ya existe. Query params que el CRM manda: `name` (LIKE parcial), `enabled`
(`1`/`0`), `per_page`.

Dos cosas a corregir, ambas menores:

- `SearchServiceRequest` valida `limit` y `offset`, pero
  `ServiceRepository::search()` lee `per_page` y los ignora. Que las reglas
  digan `per_page`.
- `ServiceResourceCollection` arma una `meta` con `current_page`, `per_page`,
  `total`, `last_page` — **sin `from` ni `to`**, que sí trae el resto de
  `admin/v1` y que espera el tipo `PagedResponse` del CRM. Por eso el CRM hoy
  pide `per_page=100` y no pagina esta pantalla. Si se completa la `meta`, se
  puede paginar.

Respuesta esperada por ítem (tras los arreglos de arriba):

```json
{
  "id": 1,
  "name": "Aire acondicionado",
  "icon": "<i class=\"fa fa-snowflake-o\"></i>",
  "type": "general",
  "is_highlighted": false,
  "enabled": true,
  "qty": 3
}
```

`qty` es la cantidad de alojamientos asociados. **Ojo con el N+1**: hoy
`ServiceResource` hace `$this->accommodations()->count()` por fila cuando no está
eager-loadeada, o sea una query por servicio. Con 7 filas no se nota, pero el fix
es una línea en el repositorio: `->withCount('accommodations')` y que el Resource
lea `accommodations_count`.

### `POST admin/v1/services`

Cuerpo que manda el CRM (exactamente estos campos, nada más):

```json
{
  "name": "Wifi",
  "type": "general",
  "icon": null,
  "is_highlighted": false,
  "enabled": true
}
```

Reglas pedidas:

| Campo | Regla |
|---|---|
| `name` | `required`, `string`, `min:3`, `max:255`, **`unique:services,name`** |
| `type` | `required`, `in:general,room,bathroom,accessibility,kitchen` |
| `icon` | `nullable`, `string`, `max:255` |
| `is_highlighted` | `boolean` |
| `enabled` | `boolean` |

`unique` en `name` es nuevo y es el punto del CRUD: sin eso, el catálogo se llena
de duplicados ("Wifi" / "WiFi" / "Wi-Fi"), cada uno con su puñado de
alojamientos, y ningún filtro por servicio devuelve la lista completa. Si duele
porque ya hay duplicados en la base, conviene limpiarlos antes con un comando y
no aflojar la regla.

Respuesta: `201` con `{ "data": { ... } }` (el CRM lee `res.data.id` para
redirigir). Hoy `store()` devuelve `200` con el Resource pelado — el `data` sale
igual porque `JsonResource` envuelve, pero el status debería ser 201.

**Importante**: el repositorio usa `$request->all()`, no `$request->validated()`.
Eso deja pasar cualquier campo que esté en `$fillable` aunque no esté validado —
es el mismo patrón que ya causó problemas en `Accommodation` (ver
`crm/CLAUDE.md`). Cambiarlo a `validated()`.

### `PATCH admin/v1/services/{service}`

Mismos campos, todos `sometimes`. El `unique` de `name` tiene que ignorar la fila
actual: `Rule::unique('services', 'name')->ignore($service->id)`.

Nota: `UpdateServiceRequest` hoy hace `'unique:services,slug,' . $this->route('id')`
— con route-model binding el parámetro pasa a llamarse `service` y `route('id')`
devuelve `null`, así que la regla queda mal armada aparte de apuntar a una
columna que no existe.

Respuesta: `200` con `{ "data": { ... } }`.

### `DELETE admin/v1/services/{service}`

El CRM tolera 204 sin cuerpo y 200 con `{message}` (su `apiFetch` lee el cuerpo
con `text()` antes de parsear, justamente por esta inconsistencia). Preferido:
`204`.

**Pedido concreto: rechazar la baja de un servicio en uso con `409`.**

`accommodation_services` no tiene FK con `ON DELETE CASCADE` (verificado: la
migración `2023_05_21_000012` es un snapshot del dump legacy), así que borrar un
servicio asociado deja filas huérfanas en el pivote apuntando a un `service_id`
que ya no existe. Eso no explota en el momento — se manifiesta más tarde como
servicios fantasma en la ficha de un alojamiento, que es mucho más difícil de
rastrear.

```php
if ($service->accommodations()->exists()) {
    return response()->json([
        'message' => 'El servicio está asociado a alojamientos. Desasocialo antes de eliminarlo.',
    ], 409);
}
```

El CRM ya trata el 409 con un mensaje propio y le ofrece al usuario la
alternativa (deshabilitar en vez de borrar). Si en cambio se prefiere borrar en
cascada, hay que limpiar el pivote explícitamente en la misma transacción —
pero la baja silenciosa de un servicio de N fichas de alojamiento me parece peor
default que el 409.

Falta también revisar `room_type_services`
(`2026_07_07_000005_create_room_type_services_table.php`), que apunta a la misma
tabla y no miré si tiene FK.

## Checklist

- [x] `Service`: sacar `slug`/`created_at`/`updated_at` de `$fillable`, agregar
      `$casts` booleanos, `$timestamps = false`, accessor `icon` ↔ `ico`
- [x] `ServiceResource`: sacar `slug`, leer `accommodations_count` para `qty`
- [x] `ServiceRepository`: `validated()` en vez de `all()`, `withCount('accommodations')`
- [x] `StoreServiceRequest` / `UpdateServiceRequest`: sacar `slug`, `unique` en
      `name`, `type` requerido en el store
- [x] `SearchServiceRequest`: `per_page` en vez de `limit`/`offset`
- [x] `ServiceResourceCollection`: agregar `from` / `to` a la `meta`
- [x] `destroy`: route-model binding + 409 si está en uso
- [x] Rutas nuevas en el grupo `platform` de `admin/v1`
- [x] Tests: alta, edición, nombre duplicado (422), baja en uso (409), baja
      libre (204), y que un usuario no-`platform` reciba 403 en las tres
      (`tests/Feature/ServiceCatalogTest.php`, 18 casos)

## Implementado — desviaciones del plan

1. **El `$fillable` lista `icon`, no `ico`.** El snippet del plan ponía `ico`,
   pero entonces `Service::create(['icon' => ...])` descarta el campo: el filtro
   de mass assignment corre *antes* que el mutator, así que la clave que se
   valida contra `$fillable` es la que manda el cliente.
2. **Las rutas legacy de `/api/v1/services` apuntaban al controlador de
   `admin/v1`** (`routes/api.php` importaba `Api\Admin\V1\ServiceController` y lo
   usaba en los dos bloques). `Api\V1\ServiceController` estaba sin uso. Como
   `admin/v1` pasó a route-model binding (`{service}`), dejarlo así rompía el
   `PUT`/`DELETE` legacy, y el `GET /api/v1/services/{id}` **ya estaba roto**:
   `show(Service $service)` contra un parámetro `{id}` no bindea nada y el
   contenedor inyectaba un `Service` vacío, devolviendo nulls. Las rutas legacy
   ahora van al controlador V1, que resuelve el id a mano.
3. **La baja quedó en dos métodos del repositorio**: `destroy(Request)` para el
   legacy (id en el cuerpo, sin el chequeo de uso) y `remove(Service)` para
   `admin/v1` (aborta 409). Es lo que pedía el plan al dejar el legacy como
   estaba, pero conviene unificarlo cuando se pueda retirar `/api/v1/services`.
4. **`store` y `update` de `admin/v1` devuelven el Resource sin
   `response()->json()`.** Con `response()->json($resource)` el JSON sale *sin* el
   envoltorio `data` — el wrapper solo lo aplica `toResponse()`. El plan asumía
   lo contrario; el CRM lee `res.data.id`, así que era necesario.

Pendiente fuera de este alcance: `room_type_services` sigue sin revisar (mismo
riesgo de huérfanos que `accommodation_services`, el 409 solo mira alojamientos),
y `Public\PublicServiceResource` todavía expone `slug`, que siempre es `null`.

## Contra qué se prueba

El CRM ya tiene la pantalla en `/settings/services` (menú **Configuración →
Servicios**). Con esto implementado, alta, edición y baja tienen que funcionar
sin tocar una línea del frontend.
