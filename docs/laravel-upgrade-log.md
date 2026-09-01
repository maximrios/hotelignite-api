# Upgrade Laravel 9 → 13 — registro

Ejecutado el **2026-08-30**. Resultado: `api/` corre **Laravel 13.29.0 sobre
PHP 8.4.25**, suite en **72/72 verde**, `composer audit` sin advisories.

La decisión de upgradear en vez de reescribir está en
`docs/production-readiness.md` §2.

---

## Lo que se hizo antes de tocar el framework

El upgrade necesitaba red de seguridad. Como las migraciones desde cero quedaron
para después (la base sale de un dump), se aprovechó que **los tests de tenencia
usan `DatabaseTransactions`** y no dependen de `migrate`.

La suite estaba en **86 failed / 9 passed**. Se la puso verde así:

1. **Base de tests reconstruida** desde un dump de dev (`hotelignite_testing`).
2. **`ChildResourceTenancyTest::createRoomType`** insertaba `name`, `created` y
   `modified` en `room_types`; ninguna de las tres existe desde que la tabla se
   normalizó (`name` vive en `room_type_descriptions`, las otras pasaron a
   `created_at`/`updated_at`).
3. **`createReservation`** no generaba `id`: `reservations.id` es `char(36)`
   desde `2026_07_24_000001_change_reservations_id_to_uuid` y dejó de
   autoincrementar. El modelo lo resuelve con `HasUuids`, pero el fixture
   inserta en crudo.
4. **Un test vacío**: `account_user_no_puede_mudar_su_room_a_otro_alojamiento`
   era sólo un `markTestSkipped('Bloqueado por la divergencia de esquema en
   rooms')`. Esa divergencia ya no existe —`rooms` tiene `created_at`/
   `updated_at`— así que se escribió el test de verdad. Verifica que
   `RoomRepository::update` descarta `accommodation_id` del payload.
5. **Bug real en `StoreUserRequest`**: le pasaba `accommodationIds()` a
   `AccommodationScopeRule::validate()`, pero ese helper ya filtra a `[]` cuando
   el tipo no es `account`, y la regla corta en seco con un array vacío. La
   guarda «solo un usuario account puede acotarse a alojamientos» era **código
   muerto**: la API aceptaba `accommodation_ids` en un usuario `platform` y los
   descartaba en silencio en vez de rechazarlos. `UpdateUserRequest` ya lo hacía
   bien (pasa el input crudo); se alineó `Store` con él.
6. **Scaffolding de Breeze retirado** (§1 del informe de readiness). No fue
   opcional: sus tests usan `RefreshDatabase`, que corre `migrate:fresh` y
   **destruye la base cargada por dump** de la que dependen los de tenencia. Con
   migraciones diferidas, los dos grupos no pueden convivir.

## PHP de desarrollo

`docker-api/Dockerfile` estaba pineado en `php:8.1-fpm` —EOL— mientras
producción corre 8.4. Se subió a `php:8.4-fpm` (+`bcmath`, +`opcache`, para
paridad con la imagen de prod) y se comentaron `session.sid_length` y
`session.sid_bits_per_character` en el `php.ini` de dev, deprecados en 8.4.

Checkpoint: Laravel 9 sobre PHP 8.4 → 72/72 verde antes de tocar composer.

## Los cuatro saltos

| Salto | Versión | Cambios de código | Suite |
|---|---|---|---|
| 9 → 10 | 10.50.3 | `$routeMiddleware` → `$middlewareAliases`; import muerto de Passport eliminado; `phpunit.xml` migrado al esquema de PHPUnit 10 | 72/72 |
| 10 → 11 | 11.56.1 | ninguno | 72/72 |
| 11 → 12 | 12.68.0 | ninguno | 72/72 |
| 12 → 13 | 13.29.0 | `laravel/tinker` ^2 → ^3 (el ^2 no soporta illuminate ^13); `spatie/laravel-ignition` removido | 72/72 |

**El skeleton slim de Laravel 11 no hizo falta.** `app/Http/Kernel.php` sigue en
su lugar y funciona en 11, 12 y 13, tal como decía el análisis previo.

### Detalles que valen la pena

- **PHPUnit 12 elimina las anotaciones en doc-comments.** Las 31
  `/** @test */` se convirtieron a atributos `#[Test]`. Sin eso, en PHPUnit 12
  esos tests **dejarían de ejecutarse en silencio** — suite verde sin probar
  nada. Se verificó que el conteo siguió en 72 después de convertir.
- **`spatie/laravel-ignition` se removió** en vez de bumpearlo: Laravel 11+ ya no
  lo incluye en su skeleton, el framework trae su propia página de error, y era
  una dependencia de más bloqueando el resolver.
- **Advisories:** en Laravel 10, `composer audit` reportaba 3 vulnerabilidades de
  `laravel/framework` **sin parche en esa rama** (afectan `<12.61.1`). Quedarse
  en 10 no habría resuelto nada. Desde Laravel 12 el audit da limpio.

## Regresión encontrada y corregida

Retirar Breeze dejó `route('login')` colgando: `app/Http/Middleware/Authenticate.php`
redirigía ahí cuando el request no pedía JSON. Cualquier request sin
`Accept: application/json` a un endpoint protegido devolvía **500 «Route [login]
not defined»** en lugar de 401.

No lo detectó la suite —los tests siempre mandan JSON—, sino la prueba HTTP real
contra nginx. Corregido en dos partes:

- `Authenticate::redirectTo()` devuelve `null` siempre: esto es una API, un
  request sin autenticar recibe 401, nunca un redirect.
- `Handler::register()` renderiza `AuthenticationException` como
  `{"message":"Unauthenticated."}` con 401 para `api/*` y `pms/*`, así el cuerpo
  no queda vacío.

Forzar JSON para *todas* las excepciones de `api/*` sigue pendiente
(`docs/production-readiness.md` §8).

## Verificación final

- `php artisan test` → 72 passed (199 assertions), sin warnings.
- `config:cache` + `route:cache` + `view:cache` → OK (es lo que hace el
  entrypoint de producción). 205 rutas registradas.
- Smoke test HTTP contra nginx con un token Sanctum 4 real:
  `/api/v1/accommodations`, `/api/admin/v1/accommodations`, `/api/admin/v1/plans`
  y `/api/v1/channels` → 200 con datos.
- Rutas de Breeze (`/register`, `/login`, `/dashboard`, `/redirect`) → 404.
- `composer audit` → sin advisories.
- `pint` corrido sobre los archivos tocados.

## Hallazgo lateral, sin resolver

`api/v1/channels` está registrada **dos veces**: como pública en
`routes/api.php:80` (`channels.index`) y otra vez dentro del grupo autenticado
(`channels.legacy.index`). Laravel se queda con la última, así que **el endpoint
público que documenta `CLAUDE.md` en realidad exige token**. Hoy juega a favor de
la seguridad, pero la documentación y el código no coinciden: hay que decidir cuál
de las dos vale y borrar la otra.

## Pendiente inmediato

Nada de esto está commiteado. Antes de seguir conviene revisar el diff y commitear
en una rama — el árbol tiene además cambios previos sin commitear (`composer.lock`,
`docker/`, `DEPLOY.md`, `.dockerignore`).
