# Estado de la versión 0.2.0-beta.1

## Entrega 1 de la v0.2 (21 de octubre de 2026)

**Google Calendar**
- Conexión OAuth con la cuenta del negocio: tres permisos exactos, `state` validado, tokens cifrados con `MO_BOOKING_SECRET`. Guía: `docs/guia-google-cloud.md`.
- Varios calendarios de lectura bloquean horarios (FreeBusy) y un calendario destino; los eventos propios del plugin no se bloquean a sí mismos. Los eventos de día completo ocupan el día local.
- Consulta fresca a Google al cotizar, reservar y reprogramar; la vista del calendario usa una caché de 3 minutos. Si Google no responde, la reserva en línea se detiene y la administradora puede crear manualmente con excepción registrada. Si nunca se conectó Google, todo funciona con la agenda interna.
- Dos eventos vinculados: interno (privado, sin invitados, con datos administrativos) y del cliente (solo al confirmar, con una invitación de Google). Correos de dominios de ejemplo no se invitan.
- Cola persistente con reintentos y espera exponencial; identificadores deterministas (sin duplicados); revocación pausa sin perder trabajos.
- Conciliación cada 10 minutos (o con el botón): empalmes posteriores (se marcan y avisan, nunca se cancela nada) y ediciones hechas en Google (movido, borrado, invitados) con restaurar o ignorar.
- Pantalla "Google Calendar", tarjeta en Inicio y bloque en cada reserva.

**Pagos y comprobantes (decisiones 1 a 5 y P1, P2, P4, P5)**
- Aprobar el anticipo confirma. "Pendiente de aprobación" migra a "Confirmada".
- Pago parcial: queda pendiente hasta las 12 h originales; si se revisa después, la reserva vence y el dinero queda "por resolver".
- Una sola corrección de 2 h con motivo de una lista editable; nota interna separada. Comprobantes versionados.
- Comprobante tardío rechazado con "Pasaste el tiempo límite para enviar tu anticipo. Realiza de nuevo tu reserva." (no se guarda).
- Imágenes se ven como copia re-codificada; PDF solo se descarga.

**Actualización segura**: la migración espera a que confirmes un respaldo; mientras, la reserva en línea queda en pausa. Guía: `docs/respaldo-y-reversion.md`.

**Otros**: aviso si la zona de WordPress no da la misma hora que la del plugin; textos de bienvenida editables en Elementor y en el shortcode (un subtítulo vacío se oculta).

**Pruebas**: 142 automáticas en PHP 7.4 y 8.3 (23 de Google con un Google simulado), recorrido en navegador con Google simulado y las 17 pantallas del panel sin errores. Pendiente: tus pruebas con Google real (`docs/pruebas-google.md`).

**Entrega 2 (pendiente)**: contrato por servicio, contactos secundarios y duplicados, importación/exportación CSV, limpieza de comprobantes de más de 90 días.

---

# Estado de la versión 0.1.1

## Cambios en 0.1.1 (pedidos por Mónica)

1. **Pantalla inicial** "Soy cliente nuevo" / "Ya me he tomado fotos con ustedes". Si la clienta es frecuente (por nombre y correo o teléfono), ve los precios de frecuente; si no se confirma, ve los precios generales y la reserva queda "frecuente por revisar".
2. **Un solo enlace con preventa automática**: tabla "Precios por temporada" en el servicio con fecha límite de preventa. Al pasar esa fecha se muestran y cobran solos los precios regulares de público general y de frecuente.
3. **Calendario nuevo**: color por tipo de precio (preventa o regular), ícono de etiqueta en las fechas con precio especial, días agotados en rojo y leyenda. El precio se ve al elegir el día.


Fecha: 20 de octubre de 2026. Alcance: **versión reducida para las minis**, acordada con Mónica (etapas A y B, más parte de C y D).

## Implementado y probado

- **Instalación**: plugin vacío, migraciones idempotentes y capacidades propias. Si el PHP es menor que 7.4, rechaza la activación con un aviso y sin error fatal.
- **Servicios**: configuración completa (duración, márgenes, intervalo, anticipación, horizonte, máximo diario, fechas de venta, personas, mascotas, extras, preguntas, anticipo fijo, porcentual o total, confirmación manual o automática, avisos por fecha y condiciones).
- **Disponibilidad**: horario semanal, fechas específicas (también por carga masiva), cierres, bloqueos y vista previa de espacios. Señala las citas que quedan fuera de horario tras un cambio, sin moverlas.
- **Precios**: tarifas finales y promociones por fecha de cita, periodo de reserva, tipo de cliente, día y hora. Rechaza reglas ambiguas. Simulador.
- **Reservas en línea**: cotización en el servidor, reserva idempotente, control de cupos con bloqueo transaccional, retención con vencimiento y conciliación sin cron.
- **Comprobantes**: almacenamiento privado verificado, validación de tipo real, límites, recepción tardía enviada a conciliación y aviso de archivo repetido en otra reserva.
- **Pagos**: aprobación sin duplicados, abonos parciales (piden la diferencia), pagos sin comprobante, devoluciones pendientes y realizadas, créditos y reversos. Saldo y estado financiero calculados de los movimientos.
- **Estados**: confirmar (con excepción justificada si falta anticipo), rechazar, completar (cuenta una sola sesión), inasistencia, reabrir. Historial con actor, momento y motivo.
- **Políticas**: editor con horas y montos libres, versiones (cada reserva conserva la que aceptó) y simulador. Cancelación por el cliente o por el negocio y reprogramación hecha por la administradora, siempre con vista previa económica.
- **Cliente frecuente**: la persona lo declara; la administradora valida y recalcula conservando la fecha de solicitud original.
- **Clientes**: expedientes con contacto normalizado (MX y EE. UU.), sesiones previas, categoría manual y revisión de duplicados. Nunca se unen expedientes solo por el nombre.
- **Portal privado**: enlace seguro, sesión con cookie, estado, desglose, saldo, datos de transferencia, subida de comprobante y políticas aceptadas.
- **Alta manual** de reservas desde el panel, con fecha de solicitud (para respetar una preventa) y anticipo ya recibido.
- **Correo**: cola, plantillas editables, recordatorio que se anula al cambiar o cancelar, reintentos con espera progresiva, registro y modo simulación.
- **WhatsApp manual**: abre el chat con el texto listo y permite marcarlo como enviado.
- **Elementor**: widget con controles de contenido y estilo y vista previa ficticia. Shortcode equivalente.
- **Datos de prueba** de Navidad, opcionales y borrables.
- **Privacidad**: exportación y anonimización con las herramientas de WordPress.

## Simulado o sin conectar

| Elemento | Situación | Cómo activarlo |
|---|---|---|
| Envío real de correos | Modo simulación por defecto. | Configurar un plugin SMTP con Gmail (OAuth), probar con un correo propio y cambiar Ajustes → Correos a "Envío real". |
| WhatsApp automático | Sin proveedor: los mensajes quedan como "sin conexión". | Requiere un proveedor oficial de WhatsApp Business API, plantillas aprobadas y credenciales. Desarrollo pendiente. |
| Validación de cliente frecuente por código | Validación manual en el panel. | Pendiente (etapa C). |
| Verificación del almacenamiento en el hosting real | Probado localmente. | En el sitio real: Ajustes → Verificar almacenamiento privado. |

## Pendiente (siguientes etapas)

- Recibos con folio y PDF (sección 27).
- Reportes por categoría, gastos y resultado estimado (sección 28).
- Galerías externas con claves en el portal (sección 29).
- Importación y exportación CSV de clientes.
- Reprogramación y cancelación solicitadas por el cliente desde el portal, con retención de dos horarios y cambio atómico.
- Límite de cambios por política.
- Vista consolidada "Mis sesiones" por cliente.
- Agenda en cuadrícula de calendario (hoy hay lista por día, semana y mes).
- Traducciones (`languages/`): el texto está en español directamente.

## Informe de pruebas

Entorno: contenedor Linux, MariaDB 10.11, WordPress 7.1.3, PHP 8.3.6 y PHP 7.4.33 (Docker), Chromium (Playwright).

| Prueba | Comando | Resultado |
|---|---|---|
| Unitarias + integración, PHP 8.3 | `MO_WP_DIR=… vendor/bin/phpunit` | 105 pruebas, 315 aserciones, todas OK |
| Unitarias + integración, PHP 7.4 | igual, en el contenedor `php:7.4` | 105 pruebas, todas OK |
| Activación en PHP 7.2 | script con WordPress simulado | Activación rechazada con aviso, sin error fatal |
| Ciclo del ZIP | instalar ZIP → activar → crear datos → desactivar → reactivar → desinstalar | Sin errores, sin pérdida ni duplicados; desinstalar conserva los datos |
| Concurrencia (AC08) | dos procesos PHP compitiendo por el mismo horario | Solo uno reserva y el otro recibe `slot_unavailable`. Con el bloqueo desactivado la prueba **falla** (se comprobó que detecta el problema). |
| Panel, todas las pantallas | `tests/e2e/admin-smoke.js` | 16 pantallas sin errores de PHP |
| Configuración desde el panel | `tests/e2e/admin-config.js` | Servicio, horarios, carga masiva, tarifas, políticas y reserva manual. Rechaza datos ambiguos o inválidos con mensajes claros. |
| Recorrido en celular, 360 px | `tests/e2e/flow.js` | Cliente nueva: reserva → comprobante → aprobación → portal. Clienta frecuente: verificación → precio $1,200 → reserva. Clienta no encontrada: precios generales. Sin desplazamiento horizontal ni errores de JS. Token alterado rechazado. |
| Tabla de precios en el panel | `tests/e2e/price-table.js` | Carga, guarda, rechaza rangos encimados conservando lo escrito. |

### Criterios de aceptación de la especificación

| ID | Estado | Evidencia |
|---|---|---|
| AC01 | ✅ | Ciclo del ZIP |
| AC02 | ✅ | Prueba en PHP 7.2 (el mínimo acordado es 7.4) |
| AC03 | ✅ | `AvailabilityTest`, `BookingFlowTest::test_availability_shows_150_slots` |
| AC04 | ✅ | `PricingTest::test_christmas_rate_matrix` (8 casos) |
| AC05, AC06 | ✅ | `PricingTest` |
| AC07 | ✅ | `PricingTest::test_presale_cutoff…`, `BookingFlowTest::test_presale_price_kept_during_hold` |
| AC08 | ✅ | `ConcurrencyTest` (procesos reales) |
| AC09 | ✅ | `AvailabilityTest`, `BookingFlowTest::test_other_service_cannot_overlap` |
| AC10 | ✅ | `BookingFlowTest::test_idempotent_retry` |
| AC11, AC12, AC13 | ✅ | `BookingFlowTest` |
| AC14 | ✅ | `BookingFlowTest` (doble aprobación y abono parcial) |
| AC15 | 🟡 | Verificación en línea por nombre y contacto (`FrequentClientTest`), sin exponer datos ni unir por nombre; falta el código de un solo uso |
| AC16 | ⏳ | CSV pendiente |
| AC17, AC18 | ✅ | `PolicyTest`, `BookingFlowTest::test_reschedule_and_cancel…` |
| AC19 | ✅ | `admin-config.js` (editar política y simulador) |
| AC20 | ✅ | `BookingFlowTest::test_policy_version_is_frozen_on_booking` |
| AC21 | 🟡 | Cambio atómico desde el panel; falta el flujo del cliente con doble retención |
| AC22, AC23, AC24 | ✅ | `BookingFlowTest` |
| AC25 | 🟡 | Sin duplicados en la cola; los webhooks de WhatsApp están pendientes |
| AC26, AC27 | ✅ | `BookingFlowTest::test_malicious_files_rejected`, `flow.js` (token alterado) |
| AC28 | 🟡 | Shortcode probado a 360 px; widget de Elementor escrito pero **sin probar en Elementor** (no está en este entorno) |
| AC29 | ✅ | Conversión con zona IANA y horas inexistentes omitidas (`AvailabilityTest`); `no-store` en la API |
| AC30 | ✅ | WhatsApp manual "abierto, no enviado"; correo "aceptado por transporte" |
| AC31 | ✅ | Modo automático sin conexión: la reserva funciona y el estado queda visible |
| AC32 | ✅ | Ciclo del ZIP |
| AC33–AC40 | ⏳ | Recibos, reportes y galerías pendientes |

## Antes de abrir reservas reales

1. Instalar en una copia del sitio (staging) o, como mínimo, con un respaldo completo reciente.
2. Ajustes: datos bancarios reales, verificar almacenamiento privado y revisar los valores propuestos (sección 26).
3. Configurar el correo autenticado, probarlo con tu propio correo y activar "Envío real".
4. Crear el servicio real, sus horarios, tarifas y políticas, y comprobarlos con los simuladores.
5. Hacer una reserva completa desde tu celular con datos tuyos.
6. Probar el widget de Elementor en tu página de reservas.
