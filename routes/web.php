<?php

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Sin rutas. Esto es una API: todo entra por routes/api.php (Sanctum) o por
| routes/client-api.php (API key de client).
|
| Acá vivía el scaffolding de Laravel Breeze, retirado el 2026-08-30. Exponía
| sobre el dominio de la API, y sin ningún throttle porque el grupo `web` no lo
| tiene: `POST /register` (alta de usuarios anónima e ilimitada), `POST /login`
| (fuerza bruta sin freno, en paralelo al `throttle:login` que sí protege
| /api/auth/login), `POST /forgot-password` (envío de mail disparado por
| anónimos) y `GET /redirect`, una ruta de debug de Passport que redirigía a
| third-party-app.com. Ningún frontend del monorepo las consumía.
|
| Ver docs/production-readiness.md §1.
|
| El healthcheck es /healthz y lo resuelve nginx sin llegar a PHP
| (docker/nginx.conf).
|
*/
