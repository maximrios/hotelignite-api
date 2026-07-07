<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| PMS / M2M Routes  (prefijo /pms)
|--------------------------------------------------------------------------
|
| DESHABILITADO durante el hardening (2026-07-07). Estas rutas estaban rotas
| y sin uso, y exponían una superficie inconsistente al salir a internet:
|
|  - `Route::resource('bookings', ...)` usaba el middleware `client`, que NO
|    está registrado (Passport no instalado) → tiraba error al invocarse.
|  - El grupo `v1` apuntaba a `Api\Pms\AccommodationController`, que no existe.
|
| El PMS (Next.js) no consume /pms/*: usa /api/admin/v1 y /api/v1 con Bearer.
| El canal M2M real (client_credentials para agencias/gobiernos) se reconstruye
| en la fase de clients. Ver docs/security-hardening-plan.md (D13) y api-readiness.md.
|
*/

// (sin rutas activas)
