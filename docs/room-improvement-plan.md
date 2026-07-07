# Plan de Mejora: Room y RoomType

> Análisis aplicando criterios de PMS, OTAs y estándares del rubro hotelero.
> Fecha: 2026-03-25

---

## Diagnóstico general

El módulo de habitaciones tiene una base de repositorio sólida pero carece de las estructuras de datos que un PMS real necesita para operar con OTAs (Booking.com, Airbnb, Expedia) y gestionar disponibilidad e inventario. Los problemas se clasifican en tres niveles de criticidad.

---

## Problemas críticos

### 1. `Room` (habitación física) está vacío

`app/Models/Room.php` existe pero no tiene ninguna implementación. En un PMS, la habitación física es la unidad operativa central: es lo que se bloquea, se asigna al huésped en check-in y se reporta como ocupado/disponible/en mantenimiento.

**Impacto:** Sin este modelo funcional, no es posible gestionar inventario real ni sincronizar disponibilidad con OTAs.

**Propuesta de migración:**
```php
Schema::create('rooms', function (Blueprint $table) {
    $table->id();
    $table->foreignId('room_type_id')->constrained()->cascadeOnDelete();
    $table->foreignId('accommodation_id')->constrained()->cascadeOnDelete();
    $table->string('number');           // ej. "101", "301-A"
    $table->unsignedTinyInteger('floor')->nullable();
    $table->enum('status', ['available', 'occupied', 'maintenance', 'blocked', 'checkout'])->default('available');
    $table->text('notes')->nullable();  // notas internas (no visibles al huésped)
    $table->timestamps();

    $table->unique(['accommodation_id', 'number']);
});
```

**Propuesta de modelo:**
```php
class Room extends Model
{
    protected $fillable = ['room_type_id', 'accommodation_id', 'number', 'floor', 'status', 'notes'];

    protected $casts = ['status' => 'string'];

    public function roomType()    { return $this->belongsTo(RoomType::class); }
    public function accommodation() { return $this->belongsTo(Accommodation::class); }
    public function reservations() { return $this->hasMany(Reservation::class); }
}
```

---

### 2. No existe sistema de tarifas (rates/pricing)

No hay ninguna entidad para precios. Sin tarifas, es imposible integrar con OTAs ni mostrar precios en el motor de reservas.

En el rubro, el modelo estándar es: `RoomType` tiene varios `RatePlan` (plan de tarifa), y cada plan tiene precios por fecha o temporada.

**Propuesta mínima viable:**
```php
// Planes tarifarios (ej. "Tarifa flexible", "No reembolsable", "Solo desayuno")
Schema::create('rate_plans', function (Blueprint $table) {
    $table->id();
    $table->foreignId('room_type_id')->constrained()->cascadeOnDelete();
    $table->string('name');
    $table->string('code')->nullable();          // código para sincronización con OTA/channel manager
    $table->enum('cancellation', ['flexible', 'moderate', 'strict', 'non_refundable'])->default('flexible');
    $table->boolean('includes_breakfast')->default(false);
    $table->boolean('enabled')->default(true);
    $table->timestamps();
});

// Precios por plan y fecha
Schema::create('rates', function (Blueprint $table) {
    $table->id();
    $table->foreignId('rate_plan_id')->constrained()->cascadeOnDelete();
    $table->date('date');
    $table->unsignedInteger('price');            // en centavos para evitar decimales flotantes
    $table->string('currency', 3)->default('ARS');
    $table->unsignedSmallInteger('min_stay')->default(1);
    $table->unsignedSmallInteger('max_stay')->nullable();
    $table->timestamps();

    $table->unique(['rate_plan_id', 'date']);
});
```

---

### 3. No existe sistema de disponibilidad (availability/inventory)

Para sincronizar con OTAs se necesita una tabla de inventario por fecha. Esta tabla registra cuántas habitaciones de cada tipo están disponibles cada día — es lo que el channel manager actualiza en tiempo real.

```php
Schema::create('room_availability', function (Blueprint $table) {
    $table->id();
    $table->foreignId('room_type_id')->constrained()->cascadeOnDelete();
    $table->date('date');
    $table->unsignedSmallInteger('available');    // habitaciones disponibles ese día
    $table->unsignedSmallInteger('total');        // total de habitaciones del tipo
    $table->boolean('closed')->default(false);   // bloqueo total (ej. mantenimiento masivo)
    $table->boolean('closed_to_arrival')->default(false);   // CTA: no se puede hacer check-in ese día
    $table->boolean('closed_to_departure')->default(false); // CTD: no se puede hacer check-out ese día
    $table->timestamps();

    $table->unique(['room_type_id', 'date']);
});
```

> `closed_to_arrival` y `closed_to_departure` son restricciones estándar en el protocolo de OTAs (OpenTravel, HTNG).

---

## Problemas de diseño

### 4. `RoomCategory` tiene la relación invertida

El modelo actual define:
```php
// RoomCategory.php — INCORRECTO
public function type()
{
    return $this->belongsTo(RoomType::class); // una categoría pertenece a UN tipo de habitación?
}
```

Esto está invertido. Una `RoomCategory` (ej. "Suite", "Habitación estándar", "Habitación superior") agrupa múltiples tipos de habitación. La relación correcta es:

```php
// RoomCategory.php — CORRECTO
public function roomTypes()
{
    return $this->hasMany(RoomType::class, 'category_id');
}
```

---

### 5. `max_occupancy` vs `quantity_max` — dos campos que hacen lo mismo

En `RoomType` existen ambos campos, y el Resource los mezcla:
```php
'max_occupancy' => $this->max_occupancy ?? $this->quantity_max,
```

En el rubro, estos son conceptos diferentes:

| Campo | Significado correcto |
|---|---|
| `max_occupancy` | Capacidad máxima de personas (ej. 3 adultos) |
| `quantity` | Cantidad de habitaciones de este tipo en el hotel |

**Propuesta:** Renombrar `quantity_max` a `quantity` y dejar ambos con propósitos distintos. Agregar también `standard_occupancy` (ocupación base para calcular suplementos por pax extra).

```php
// En la migración de room_types:
$table->unsignedTinyInteger('quantity');           // cantidad de habitaciones del tipo
$table->unsignedTinyInteger('standard_occupancy'); // ocupación base (sin suplemento)
$table->unsignedTinyInteger('max_occupancy');      // capacidad máxima
```

---

### 6. El nombre del `RoomType` vive en dos lugares a la vez

`StoreRoomTypeRequest` valida `name` y `description` como campos directos, pero `RoomType.fillable` no los incluye — viven en `RoomTypeDescription`. El resultado es que:

- Se aceptan `name`/`description` en el request de creación
- Pero `RoomType::create($request->all())` los ignora silenciosamente
- El nombre real solo existe si se crea un `RoomTypeDescription` aparte

**Propuesta:** Eliminar `name` y `description` de `StoreRoomTypeRequest`/`UpdateRoomTypeRequest` y documentar que la descripción multilenguaje se gestiona exclusivamente vía `/api/v1/room-type-descriptions`. Alternativamente, si se quiere soportar creación en un solo paso, el repositorio debe crear el `RoomTypeDescription` automáticamente dentro de la misma transacción.

---

### 7. `status` en `RoomType` no tiene valores definidos

El campo `status` de `RoomType` es un string libre sin validación de valores posibles. En OTAs y PMS el estado de un tipo de habitación tiene un conjunto fijo de valores.

**Propuesta:** Validar en el request y castear en el modelo:
```php
// StoreRoomTypeRequest / UpdateRoomTypeRequest
'status' => ['nullable', Rule::in(['active', 'inactive', 'maintenance'])],

// RoomType.php
protected $casts = [
    'status' => 'string',
];
```

---

### 8. `AccommodationRoom` es un modelo sin implementación

`app/Models/AccommodationRoom.php` solo tiene la relación `accommodation()` y ningún fillable ni propósito claro. Si es una tabla pivot entre `Accommodation` y `Room`, debería usarse dentro de `Accommodation` como relación `hasMany(Room::class)` directa (dado que `rooms` ya tiene `accommodation_id`). Si cumple otro rol, necesita ser definido.

---

## Mejoras de escalabilidad (para integración con OTAs)

### 9. Agregar `bed_type` / `bed_configuration`

Las OTAs requieren información de camas para la ficha de habitación. Es uno de los campos más consultados por el huésped.

```php
// En room_types o en una tabla room_type_beds:
Schema::create('room_type_beds', function (Blueprint $table) {
    $table->id();
    $table->foreignId('room_type_id')->constrained()->cascadeOnDelete();
    $table->enum('type', ['single', 'double', 'twin', 'queen', 'king', 'sofa_bed', 'bunk']);
    $table->unsignedTinyInteger('quantity')->default(1);
});
```

---

### 10. Categorizar los servicios (`Service`)

Actualmente `Service` es una lista plana. Para mostrarlo correctamente en el frontend y mapearlo a OTAs (que tienen taxonomías propias de amenities), se necesita categorización:

```php
Schema::table('services', function (Blueprint $table) {
    $table->enum('type', [
        'general',        // servicios del hotel (piscina, estacionamiento)
        'room',           // servicios de habitación (wifi, TV, caja fuerte)
        'bathroom',       // amenities de baño
        'accessibility',  // accesibilidad (rampa, silla de ruedas)
        'kitchen',        // cocina/kitchenette
    ])->default('general')->after('slug');
    $table->boolean('is_highlighted')->default(false)->after('type'); // para mostrar primero en listados
});
```

---

### 11. Agregar `slug` a `RoomType`

`Accommodation` y `City` ya usan slug. `RoomType` se busca por ID entero, lo que expone la estructura interna y complica URLs amigables para SEO en motores de reserva.

```php
Schema::table('room_types', function (Blueprint $table) {
    $table->string('slug')->nullable()->unique()->after('accommodation_id');
});
```

---

## Resumen de prioridades

| # | Problema | Prioridad | Impacto |
|---|---|---|---|
| 1 | `Room` vacío — habitación física sin implementar | Crítica | PMS no puede operar |
| 2 | Sin sistema de tarifas (`rate_plans`, `rates`) | Crítica | Imposible integrar OTAs |
| 3 | Sin sistema de disponibilidad (`room_availability`) | Crítica | Imposible sincronizar inventario |
| 4 | `RoomCategory` relación invertida | Alta | Consultas incorrectas |
| 5 | `max_occupancy` vs `quantity_max` ambiguo | Alta | Datos inconsistentes |
| 6 | `name`/`description` en dos capas sin coherencia | Alta | Datos que se pierden silenciosamente |
| 7 | `status` sin enum/validación de valores | Media | Datos sucios |
| 8 | `AccommodationRoom` sin propósito definido | Media | Dead code |
| 9 | Sin `bed_type`/`bed_configuration` | Media | Requerido por OTAs |
| 10 | `Service` sin categorización | Media | Listados desordenados, mapeo OTA imposible |
| 11 | `RoomType` sin `slug` | Baja | SEO y URLs |

---

## Orden de implementación sugerido

```
Fase 1 — Base operativa
  └── Implementar Room (modelo, migración, repositorio, resource, rutas)
  └── Corregir RoomCategory relación
  └── Consolidar max_occupancy / quantity_max
  └── Resolver inconsistencia name/description en StoreRoomTypeRequest

Fase 2 — Motor de precios y disponibilidad
  └── RatePlan + Rate (tarifas)
  └── RoomAvailability (inventario por fecha)
  └── Conectar Room con Reservation (asignación de habitación física)

Fase 3 — Enriquecimiento de datos para OTAs
  └── RoomTypeBed (configuración de camas)
  └── Service.type (categorización de servicios)
  └── Slug en RoomType
```
