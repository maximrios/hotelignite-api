# Prerequisito: migraciones reproducibles + CI

Este es el trabajo que va **antes** del upgrade de Laravel y antes de cualquier
otro punto de `docs/production-readiness.md`. No porque sea lo más urgente en
riesgo, sino porque es lo que hace que todo lo demás sea verificable: sin una
base que se pueda reconstruir y una suite que corra sola, un upgrade 9→13 es a
ciegas.

Todo lo que sigue está **medido**, no estimado. Método: las migraciones se
corrieron contra una base descartable (`hi_migtest`) y la suite contra
`hotelignite_testing`. La base de dev no se tocó (verificado después: 75 tablas,
77 alojamientos, 1356 reservas, 9 usuarios).

---

## Estado verificado (2026-08-30)

| Medición | Resultado |
|---|---|
| `php artisan migrate` desde cero | falla en la migración **15 de 73** |
| Con **una línea** corregida | las **73** corren completas, 0 pendientes |
| Tablas que crean las migraciones | 52 |
| Tablas en la base de dev | 75 (23 son legacy muertas) |
| Diferencia de foreign keys | **1 sola** (28 en dev vs 27 migrando) |
| Suite completa hoy | **86 failed, 9 passed** |

La conclusión que no esperaba: **el esquema y las migraciones están mucho más
cerca de lo que decía la documentación.** No hay una divergencia estructural; hay
tres agujeros puntuales, y el que bloquea todo es un error de transcripción.

---

## Las tres brechas exactas

### 1. FK fantasma a `rooms` — bloquea todo

`database/migrations/2023_05_21_000015_create_reservations_table.php` trae, dentro
de su `CREATE TABLE` crudo:

```sql
CONSTRAINT `reservations_room_id_foreign` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON DELETE SET NULL
```

`rooms` se crea 12 migraciones después (`2026_03_25_000002`), así que revienta con
`SQLSTATE[HY000]: General error: 1824 Failed to open the referenced table 'rooms'`.

Lo importante, y lo que cambia el arreglo: **esa FK no existe en la base de dev**
(las únicas FKs de `reservations` ahí son `rate_plan_id → rate_plans`). Y la
migración `2026_03_25_000006_add_room_id_to_reservations_table` documenta
explícitamente la decisión contraria:

> *Sin FK a `rooms`: en la BD legacy `rooms.id` es `int`, incompatible con el
> `bigint unsigned` de `foreignId()`. Se deja la columna indexada y la integridad
> a nivel app, como el resto del esquema legacy.*

O sea: la línea es un error de transcripción al armar el snapshot. **Borrarla no
cambia el esquema — lo corrige para que coincida con la realidad.**

**Fix:** eliminar esa línea y la coma que la precede en
`KEY reservations_status_id_index (status_id),`. Nada más. Verificado: con eso
solo, las 73 migraciones corren enteras.

### 2. FK faltante `reservations.rate_plan_id → rate_plans`

Es la única diferencia de FK entre la base de dev y una migrada desde cero.

Causa: el snapshot de `reservations` ya crea la columna `rate_plan_id`, así que la
guarda `if (! Schema::hasColumn(...))` de
`2026_05_21_000001_add_pms_fields_to_reservations_table` (línea 53) se saltea el
bloque — y con él el `->constrained()->nullOnDelete()`.

**Fix:** migración nueva e idempotente que agregue la FK sólo si no existe. Es
no-op en dev y en producción, que ya la tienen.

### 3. Tabla `reservations_status` que ninguna migración crea

`app/Models/Status.php` declara `protected $table = 'reservations_status'`, y
`Reservation` pertenece a `Status`. Ninguna migración la crea: en una base fresca,
cualquier consulta de estado de reserva explota.

**Fix:** migración que la cree (`id`, `name`, timestamps) más un seeder con los
estados que hoy tiene dev. Conviene volcarlos tal cual están:

```bash
docker exec db mysqldump -uroot -p123456789 --no-create-info \
    hotelignite reservations_status
```

### Las otras 22 tablas legacy: no las usa nadie

Verificado con grep sobre `app/`, `routes/` y `database/seeders/` — cero
referencias desde el código a:

`accommodation_images`, `cash`, `cash_concepts`, `cash_flow`, `cash_status`,
`oauth_access_tokens`, `oauth_auth_codes`, `oauth_clients`,
`oauth_personal_access_clients`, `oauth_refresh_tokens`, `reservation_rooms`,
`reservations_accounts`, `reservations_accounts_concepts`, `reservations_extras`,
`reservations_guests`, `reservations_payments`, `reservations_vehicles`,
`room_images`, `room_type_categories`, `room_types_old`, `tour_images`,
`tour_services`.

(Las `oauth_*` son del Passport que se sacó; `*_images` quedaron reemplazadas por
la tabla polimórfica `images`; `room_types_old` es el respaldo de la
normalización de tipos de habitación.)

**No hace falta migrarlas.** Quedan como deuda de limpieza: verificar que estén
vacías o archivarlas, y dropearlas de producción en una ventana aparte. No es
parte de este trabajo.

---

## Estado real de los tests

Acá había dos cosas anotadas que resultaron falsas. Vale corregirlas porque
cambian el plan:

**«La suite completa borra la base de dev.»** Ya no. `phpunit.xml` fija
`DB_DATABASE=hotelignite_testing` y trae el comentario que explica por qué. La
corrida de verificación lo confirmó: dev quedó intacta. La regla de usar siempre
`--filter` dejó de ser necesaria.

**«Los tests de tenencia están verdes (13 + 1 skipped).»** No: hoy son **14
failed**, y la suite completa da **86 failed / 9 passed**. Se rompieron solos, sin
que nadie tocara la tenencia. Son dos causas distintas:

| Grupo | Estrategia | Por qué falla |
|---|---|---|
| `AuthenticationTest`, `RegistrationTest`, `PasswordResetTest`, `PasswordUpdateTest`, `PasswordConfirmationTest`, `EmailVerificationTest`, `ProfileTest`, `ExampleTest` | `RefreshDatabase` | corren `migrate:fresh` → chocan con la brecha 1 |
| `AccommodationTenancyTest`, `ChildResourceTenancyTest`, `AccommodationExportTest`, `ServiceCatalogTest`, `UserManagementTest` | `DatabaseTransactions` | fixtures escritos contra un esquema que ya cambió |

El segundo grupo es el interesante. `ChildResourceTenancyTest::createRoomType`
inserta en crudo:

```php
DB::table('room_types')->insertGetId([
    'accommodation_id' => $accommodation->id,
    'name' => 'Tipo test',
    'created' => now(),
    'modified' => now(),
]);
```

Ninguna de esas tres columnas existe hoy en `room_types` — ni en dev ni en el
esquema de las migraciones. `name` se movió a `room_type_descriptions`
(multilenguaje) y `created`/`modified` pasaron a `created_at`/`updated_at`. El
esquema se normalizó y los tests quedaron atrás.

Esto es exactamente el costo de no tener CI: **la red de seguridad de la tenencia
—lo más caro que puede romperse en este sistema— lleva semanas caída y nadie se
enteró.**

Nota al margen: los tests que usan `DatabaseTransactions` lo hacen porque el
esquema venía de un dump irreproducible. Arreglada la brecha 1, ese motivo
desaparece y pueden pasar a `RefreshDatabase`, que es más estricto y no depende de
que alguien mantenga a mano la base de tests.

---

## Plan

### Fase 1 — Migraciones reproducibles (medio día)

1. Borrar la FK fantasma del snapshot de `reservations` (brecha 1).
2. Migración idempotente para la FK `rate_plan_id` (brecha 2).
3. Migración + seeder para `reservations_status` (brecha 3).
4. Verificar contra una base descartable, no contra dev:
   ```bash
   docker exec db mysql -uroot -p123456789 -e "DROP DATABASE IF EXISTS hi_migtest; CREATE DATABASE hi_migtest CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
   docker exec -e DB_DATABASE=hi_migtest api sh -c 'cd /var/www && php artisan migrate --force'
   ```
   Criterio de aceptación: 0 pendientes, y el diff de FKs contra dev queda vacío.
5. Recién ahí, `RUN_MIGRATIONS=true` deja de ser una bomba en `docker/.env.production`.

### Fase 2 — Tests verdes (uno o dos días)

6. Actualizar los fixtures de los cinco tests de `DatabaseTransactions` al esquema
   actual (el grueso es `room_types` y `rooms`).
7. Pasarlos de `DatabaseTransactions` a `RefreshDatabase`, ahora que las
   migraciones reconstruyen el esquema solas.
8. Borrar los tests del scaffolding de Breeze (`tests/Feature/Auth/*`,
   `ProfileTest`, `ExampleTest`): esas rutas se van por el punto 1 del informe de
   readiness, así que los tests se van con ellas. Eso solo ya limpia buena parte
   de los 86 fallos.
9. Criterio de aceptación: `php artisan test` verde de punta a punta, sin
   `--filter`.

### Fase 3 — CI (medio día)

10. `.github/workflows/ci.yml`: servicio MySQL 8, `composer install`,
    `php artisan migrate`, `php artisan test`, `./vendor/bin/pint --test`.
11. Correrlo en push y en pull request contra `master`.
12. Marcar el workflow como check requerido para mergear.

### Fase 4 — Recién ahora, el upgrade

13. Con CI verde, `laravel/framework` 9 → 10 → 11 → 12 → 13, un PR por salto.
    Ver el punto 2 de `docs/production-readiness.md`.

---

## Por qué este orden y no otro

La tentación es empezar por lo que más asusta (Laravel EOL, el rate limit, los
backups). Pero los backups y el rate limit son configuración: se hacen en
paralelo y no dependen de nada de esto.

El upgrade sí depende. Un salto de versión mayor toca el framework debajo de 384
archivos, y la única forma de saber si rompiste la tenencia entre hoteles es una
suite que la verifique. Esa suite existe, está bien escrita, y hoy está roja por
razones que no tienen nada que ver con la tenencia. Repararla cuesta uno o dos
días y convierte el upgrade de apuesta en trámite.
