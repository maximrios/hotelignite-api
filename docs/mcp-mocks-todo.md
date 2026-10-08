# TODO — Datos simulados del MCP del viajero

> Registro de cada dato que el MCP del viajero (`docs/mcp-traveler-plan.md`)
> completa con valores simulados mientras no existe el dato real. Cada fila es un
> TODO: cuando se resuelve, se borra el mock y la fila.
> Fecha: 2026-10-01. Estado de los datos en dev (TuriNorte, 17 alojamientos visibles): 0 room types, 0 filas de inventario, 0 tarifas, 0 políticas → hoy toda la disponibilidad y todo precio son mock.

## Reglas

- El mock solo se usa **donde falta el dato real** (híbrido) y siempre sale marcado
  con `source: "mock"`.
- Flag `AI_MOCK_MISSING_DATA` (default `false`). Con `APP_ENV=production` se ignora:
  en producción nunca hay mocks.
- Determinístico: misma entrada (`accommodation_id`, fechas, huéspedes) → misma
  salida.
- Código en `App\AI\Support\Mock\*`. Cada clase referencia su fila de este archivo.

## Pendientes

| # | Dato simulado | Dónde se usa | Cómo se simula | Qué falta para el dato real | Bloqueante de producción |
|---|---|---|---|---|---|
| M1 | **Inventario de una noche sin filas** en `room_availability` | `check_availability`, `search_availability` | ~95% de las noches disponibles, según hash de `accommodation_id` + fecha (se aplica por noche: con 80%, 4 noches salían sin lugar más de la mitad de las veces) | Carga de inventario por los hoteleros (PMS / channel manager). Con mocks apagados, esas noches salen `unknown` | No: `unknown` es un comportamiento válido. Pero el agente pierde utilidad |
| M2 | **Precio "desde"** sin `rates` cargadas | `check_availability`, `search_availability` | Rango en ARS por tipo de alojamiento (p. ej. hostel < cabaña < hotel), con variación por fecha | Exponer `rates` en `App\AI`; decidir qué rate plan define el "desde" (ver decisión abierta 2 del plan); política de moneda | **Sí**: sin precio real, `price_from` no se devuelve |
| M3 | **`max_occupancy` vacío** en un room type | filtro de capacidad | 2 personas | Completar el dato en la ficha (validación obligatoria en el PMS) | No: sin dato, el room type no filtra y la disponibilidad no pasa de `unknown` |
| M4 | **Alojamiento sin room types** | todas las de disponibilidad, `rooms` de la ficha | Doble/triple/familiar; "Unidad completa" para cabañas y alquiler temporario; cama compartida + privada para hostels | Carga de tipos de habitación en el PMS | No: sin room types, `unknown` |

## Ya identificado, todavía sin mock

| # | Dato | Situación |
|---|---|---|
| P1 | Traducciones de descripciones a otros idiomas | Solo `es`. El resto cae a `es` (ver *Idioma* en el plan) |
| P2 | Políticas de cancelación por rate plan | `rate_plans.cancellation` existe pero no está normalizado; el MVP muestra solo la política del alojamiento |
| P3 | Políticas del alojamiento (`accommodation_policies`) | Ningún alojamiento visible para TuriNorte las tiene cargadas: `policies` sale `[]` y el agente invita a consultar. No se simulan: inventar si se aceptan mascotas es peor que no decirlo |
| P4 | Servicios | La mayoría de los alojamientos no tiene servicios cargados (`services: []`). La búsqueda por texto igual encuentra por descripción |

## Cómo verificar que un mock se puede borrar

1. Con `AI_MOCK_MISSING_DATA=false`, correr las preguntas guardadas del plan
   (*Probarlo sin chat*, punto 3).
2. Si las respuestas siguen siendo útiles con el dato real (o con `unknown`), se
   borra la clase mock, su test y la fila.
