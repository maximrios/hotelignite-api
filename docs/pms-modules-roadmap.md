# PMS — módulos a implementar

> **Estado:** registrado el 2026-11-03. **La caja está implementada en dev**
> (2026-11-03, sin commit ni deploy). Proveedores, facturación y cuotas siguen
> planificados, sin implementar.

## Resumen

| Módulo | Feature (slug) | Plan previsto | Hoy en `PlanFeatureSeeder` |
|---|---|---|---|
| Caja del hotel | `cash_register` | Básico (`starter`) en adelante | **Básico** (movida 2026-11-03) |
| Proveedores | — (falta crearla, p. ej. `suppliers`) | Básico (`starter`) en adelante | No existe |
| Facturación | `invoicing_arca` | Básico (`starter`) en adelante | Premium (`enterprise`) |
| Cuotas de cámara vistas por el hotel | — | A definir | No existe |

Ninguno entra en el plan **Free**.

Los slugs de los planes no coinciden con sus nombres comerciales: `starter` es
"Básico" y `enterprise` es "Premium" (ver `PlanSeeder`).

## Lo que hay que cambiar cuando se implemente

Esto todavía **no se tocó**. Se hace cuando cada módulo esté listo, no antes:

1. **`PlanFeatureSeeder`:** mover `invoicing_arca` al bloque `$starter` y agregar
   la feature de proveedores. Los tiers son acumulativos, así que Pro y Premium
   las heredan. (`cash_register` ya se movió.)
2. **`FeatureSeeder`:** agregar la feature de proveedores (módulo `operations`).
3. **`AccommodationBillingRepository::SHOWCASE`:** sumar proveedores a la
   comparativa de planes. (Caja ya está.)

> ⚠️ **Hoy ya se ofrece algo que no existe.** `SHOWCASE` muestra "Facturación ARCA"
> como incluida en Premium, y el módulo no está implementado. Mientras tanto,
> conviene marcarla como "próximamente" en la comparativa o sacarla.

## Módulos

### Caja del hotel — implementada en dev (2026-11-03)

Diseño acordado con el agente `hoteleria`; decisiones de Maxi: todos los usuarios
del hotel con los mismos permisos (no hay roles en la cuenta), ARS y USD desde el
inicio, MVP completo, y los cobros que no son efectivo se cargan sin turno abierto.

- **API:** migración `2026_11_03_000002_create_cash_register_tables`, modelos
  `Cash*`, `CashRepository`, `CashController`, rutas `api/v1/cash/*`. Tests:
  `CashRegisterTest` (17).
- **PMS:** `/caja` (`components/caja/`, `lib/caja/`), sección "Cobros" en el
  detalle de la reserva (`ReservationPayments`) y CSV vía `app/api/cash/export`.
- Turno con fondo por moneda, uno abierto por alojamiento (índice único
  parcial). Cierre con arqueo sólo de efectivo; la diferencia queda como
  movimiento de ajuste y exige nota.
- Libro inmutable: se anula con un asiento inverso en el turno actual. El
  efectivo exige caja abierta; los demás medios entran sin turno.
- Saldo de la reserva calculado con sus cobros (no se guarda). Cobro en otra
  moneda: el usuario carga el equivalente; no hay tipo de cambio.
- Reportes por fecha del dinero, por moneda, con lo operativo separado de
  retiros, aportes y depósitos.

Quedó afuera, para más adelante:
- Roles (dueño / recepción) para restringir anulaciones de turnos cerrados,
  retiros y reportes.
- Varias cajas por alojamiento (la tabla `cash_registers` ya existe).
- Cheques, conciliación bancaria y de Mercado Pago, comisiones de tarjeta.
- Pagos online (`payment_gateway`) entrando solos como movimiento.
- Reabrir un turno cerrado.
- Umbral configurable para exigir nota en el arqueo (hoy: cualquier diferencia).
- `reservations.deposit_amount` sigue como dato declarado; la ficha lo muestra
  como "seña declarada sin cobro registrado" si no hay movimientos.

### Proveedores

Lleva el padrón de proveedores del hotel (lavandería, alimentos, mantenimiento,
servicios), sus compras o facturas recibidas, los pagos y la cuenta corriente.

A analizar:
- Pagos a proveedores: ¿salen de la caja del hotel? Probablemente sí, como egreso.
- Alcance inicial: ¿solo el padrón y los pagos, o también órdenes de compra y stock?
- No confundir con los "prestadores" del padrón de clients
  (`clients/docs/providers-plan.md`), que son otra cosa.

### Facturación

Emite comprobantes electrónicos a ARCA (ex AFIP) a los huéspedes o empresas, a
partir de una reserva o de un cobro de la caja.

A analizar:
- Certificado y punto de venta del hotel: el hotel factura con su propio CUIT.
- Tipos de comprobante (A, B, C), notas de crédito, condición frente al IVA.
- Relación con `accounting_export` (Premium), que sigue aparte.
- Implementación con integración directa al WS de ARCA o con un proveedor intermedio.

### Cuotas de cámara vistas por el hotel

Postergado el 2026-11-02, fuera de esta fase.

Las cuotas de socios (`membership_fees`, `member_fee_assignments`,
`member_ledger_entries`) hoy solo se ven desde el portal `clients/`: la cámara
lleva el libro de lo que cobra a sus socios. El alojamiento ya está modelado como
socio (`AssociateType::ACCOMMODATION`), pero el PMS no muestra nada.

La idea es una vista **de solo lectura** en el PMS con el estado de cuenta del
alojamiento con cada cámara: saldo, cuotas pendientes y pagos registrados.

- Endpoint en `routes/pms.php` que reutilice `DuesRepository::standing()` y la
  cuenta del socio, filtrado por los alojamientos del usuario `account`.
- Tarjeta en el dashboard o sección en la ficha del alojamiento.
- **Decidir antes** si la cámara elige mostrarle la deuda al socio. Sería un
  ajuste por client, porque algunas prefieren manejarlo por fuera.

Si se monta junto con la caja, el pago de la cuota podría salir de la caja del
hotel como egreso, pero son libros distintos: el de la cámara lo lleva la cámara.
