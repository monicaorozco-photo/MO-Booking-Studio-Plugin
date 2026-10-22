# Entrega: MO Booking Studio 0.2.0-beta.2 (preventa controlada)

Fecha: 22 de octubre de 2026. Fuente de requisitos: `Especificacion_Maestra_MO_Booking_Studio_Preventa.md`. Alcance: **P0 y P0 condicional, con Salida B**. Esta versión **no está lista para producción**: falta la prueba de punta a punta en el subdominio HTTPS con Google real, correo real y cron real.

Leyenda de evidencia: **Auto** = prueba automatizada reproducible (PHPUnit, PHP 7.4 y 8.3, WordPress 7.1.3 real con MariaDB 10.11; Google simulado). **Nav** = navegador Chromium con Playwright contra un sitio local servido con `php -S` (no es LocalWP). **ZIP** = instalación desde el ZIP en una instalación aparte de WordPress con PHP 7.4.

## 1. Versión entregada

- Plugin `0.2.0-beta.2`, esquema de datos `3`.
- ZIP: `mo-booking-studio-0.2.0-beta.2.zip` (hash SHA-256 en `mo-booking-studio-0.2.0-beta.2.zip.sha256`, junto al ZIP; no puede ir dentro de este documento porque el documento viaja dentro del ZIP).
- Código: rama `v0.2.0-beta.2-preventa` del repositorio, igual al ZIP (el ZIP excluye pruebas, Composer y Node).

## 2. Alcance implementado

| P0 | Estado |
|---|---|
| 1. Correcciones de seguridad SEG-01 a SEG-06 | Implementado. Ver CHANGELOG. |
| 2. Formulario final (6.3/6.4) | Implementado. |
| 3. Reserva, retención de 12 h y doble apartado | Se conserva lo existente (bloqueo transaccional) y se probó de nuevo. |
| 4. Enlace privado y carga segura | Se conserva y se endurece la carpeta privada; texto editable junto a la carga. |
| 5. Revisión, rechazo, corrección y aprobación | Se conserva; se agregan "Otro motivo", excepción auditada y comprobante "Sustituido". |
| 6. Contrato editable, obligatorio y congelado | Implementado (nuevo). |
| 7. Google Calendar | Se conserva; se ajustan contenidos de eventos, invitación (SEG-03), migración de destino (SEG-05) y reencolado al reconectar. |
| 8. Editar, cancelar, archivar y restaurar | Implementado para reservas, servicios y clientes. |
| 9. WhatsApp y mensajes editables | Pestaña nueva con `noopener noreferrer`; plantillas existentes editables; textos del portal editables. |
| 10. Pruebas de actualización, seguridad y flujo | 175 PHPUnit + recorridos de navegador; ver secciones 5 a 7. |
| P0 condicional (CSV y frecuentes) | **Salida B**: precio frecuente automático deshabilitado; la administradora aplica la tarifa con acción auditada. CSV, contactos secundarios y duplicados **no** se entregan en esta beta. |

## 3. Decisiones técnicas relevantes

1. **Salida B**: la verificación por código (OTP) necesita correo real comprobado y el CSV seguro no podía terminarse con garantías hoy. `FrequentCheck::automaticEnabled()` devuelve `false`: no se busca a nadie al identificarse, ningún token cambia el precio y la clienta ve "precios para público general; revisaremos tu tarifa antes de confirmar".
2. **Carpeta privada**: solo una carpeta fuera de WordPress y del document root se considera privada sin más pruebas; dentro de uploads se verifica la URL exacta con TLS; cualquier otra ubicación dentro del sitio se rechaza por ambigua.
3. **Invitación independiente del estado**: `Bookings::inviteAllowed()` = confirmada y (anticipo cubierto, anticipo cero o invitación explícita). Una cita ya invitada conserva su evento aunque después se revierta un pago (para no enviar cancelaciones sorpresa); revertir no reinvita.
4. **Migración de destino con `events.move`** de Google: evita duplicar eventos y reenviar invitaciones; se verifica el evento en el calendario nuevo antes de actualizar el registro.
5. **Contratos como plantillas reutilizables con versiones inmutables**; "Políticas" se renombró "Contratos" y las reglas económicas de cancelación quedaron como pestaña (siguen usándose al cancelar desde el panel).
6. **Entrega rápida como extra reservado** (`entrega-rapida`) agregado al cotizar; al recotizar una reserva, los extras contratados conservan nombre y precio.
7. **Archivar = solo visibilidad**; eliminar definitivamente solo reservas de prueba sin pagos, comprobantes, contrato aceptado ni mensajes enviados.
8. **No se guarda la IP** al aceptar el contrato (no hay política de privacidad que la cubra).

## 4. Migraciones realizadas

Esquema 2 → 3, versionada, idempotente y detrás de la confirmación de respaldo:

- Columnas nuevas en `mo_bookings`: `children`, `adults`, `important_info`, `contract_id`, `contract_version`, `contract_title`, `contract_body`, `contract_hash`, `contract_accepted_at`, `invite_override`, `cancel_outcome`, `archived_at`.
- Columna nueva en `mo_clients`: `archived_at`.
- Tablas nuevas `mo_contracts` y `mo_contract_versions`.
- Paso `3_contracts_from_terms`: condiciones del servicio + generales → contrato v1 por servicio. Los textos anteriores se conservan.
- No se transforman ni recalculan datos existentes.

## 5. Pruebas automatizadas ejecutadas

`MO_WP_DIR=… vendor/bin/phpunit` (instrucciones en `tests/README.md`):

- PHP 8.3: **175 pruebas, todas pasan**.
- PHP 7.4.33 (Docker): **175 pruebas; 174 pasan y 1 se omite** (recodificación de imágenes: esa imagen de PHP no tiene GD).
- Archivos nuevos: `StorageSecurityTest`, `BookingFormTest`, `ContractTest`, `AdminActionsTest`. Ampliados: `GoogleCalendarTest` (29 pruebas), `MigrationTest`, `ProofReviewTest`, `FrequentClientTest`.
- Dos pruebas se comprobaron con mutación (fallan al quitar la corrección): limpieza del archivo huérfano y reencolado al reconectar Google.
- Google siempre es simulado (`tests/Fixtures/FakeGoogle.php`).

## 6. Pruebas manuales y de navegador ejecutadas (entorno local, no HTTPS)

- **Nav** `tests/e2e/preventa.js` (teléfono 360 px y teclado): 37 verificaciones, todas pasan.
- **Nav** `tests/e2e/admin-preventa.js` (panel): 16 verificaciones, todas pasan.
- **Nav** `admin-smoke.js`: 24 pantallas del panel sin errores de PHP. `admin-config.js` y `price-table.js` funcionan.
- **Nav** actualización beta.1 → beta.2 de un sitio local con reservas, pagos, comprobantes y eventos de Google simulados: pantalla de respaldo, reserva en línea en pausa (503), migración y reanudación. Conteos y hashes de pagos, eventos y ajustes iguales antes y después; la única reserva que cambió venció por tiempo al reanudarse (comportamiento normal).
- **ZIP** (PHP 7.4): instalación limpia, desactivar/reactivar, desinstalar sin borrar; reversión beta.2 → beta.1 → beta.2 conservando una reserva con contrato y entrega rápida.

## 7. Pruebas no ejecutadas

- Todo lo del subdominio HTTPS (20.3): OAuth con redireccionamiento real, revocación y reconexión reales, invitación y actualización reales en Google, correo con SMTP autenticado, cron real y vencimientos sin visitas, permisos reales del sistema de archivos, intento de acceso público al comprobante en el hosting, caché/CDN, límites de PHP y base de datos, zona horaria del servidor, migración del calendario destino con calendarios reales, respaldo y restauración con UpdraftPlus.
- LocalWP (no se usó; el sitio local fue `php -S`).
- Elementor en el editor real (solo shortcode en el navegador; el widget usa el mismo código).
- Tableta y lectores de pantalla reales; navegación con teclado del calendario completo (solo se probó el orden de foco del formulario y la activación con Enter/espacio).
- Desinstalación con borrado confirmado en un hosting real.
- SEG-06 sin `mbstring` o sin `sodium` (requiere un PHP sin esas extensiones; revisado en código).
- CSV, duplicados y contactos (pospuestos).

## 8. Criterios de aceptación

| AC | Resultado | Evidencia |
|---|---|---|
| AC-01 Formulario | **Pasa** | Auto `BookingFormTest`; Nav orden exacto de los 10 campos, sin 6, niños + adultos, mascotas aparte, sin la leyenda, entrega rápida al final y total actualizado. |
| AC-02 Reserva simultánea | **Pasa** | Auto `ConcurrencyTest` (dos procesos PHP); Nav dos navegadores al mismo horario: uno gana y el otro ve "alguien acaba de apartar ese horario". |
| AC-03 Retención | **Pasa (local)** | Nav hora exacta de vencimiento; Auto vence, libera y borra el evento pendiente (Google simulado). Cron real sin probar. |
| AC-04 Comprobante | **Pasa (local)**; pendiente en HTTPS | Nav carga desde el enlace privado; panel 200 con sesión, 400 sin sesión; carpeta fuera del sitio sin URL. Falta intentar el acceso público en el hosting. |
| AC-05 Corrección | **Pasa** | Auto: una corrección de 2 h, versión anterior "Sustituido", motivo de lista u "Otro", excepción auditada. |
| AC-06 Aprobación | **Pasa (Google simulado)** | Auto: confirma una vez, registra pago, exactamente una invitación. Falta Google real. |
| AC-07 Excepción | **Pasa (Google simulado)** | Auto: confirmar sin anticipo no invita; "Invitar al cliente sin anticipo" invita una sola vez con motivo. |
| AC-08 Contrato | **Pasa** | Auto `ContractTest`; Nav: se publica la v2 y la reserva sigue mostrando la v1 palabra por palabra, con hash. |
| AC-09 Calendar privado | **Pasa (Google simulado)** | Auto `test_event_contents_follow_spec`: sin teléfono, pagos, notas, información privada, panel, token ni referencia. |
| AC-10 Calendar interno | **Pasa (Google simulado)** | Auto: información administrativa completa y sin invitados. |
| AC-11 Reintentos | **Pasa (Google simulado)** | Auto: fallos 503, respuesta perdida (409), reintentos y migración con fallo intermedio sin duplicar eventos ni invitaciones; reservas idempotentes; pagos con clave única; avisos con clave única. |
| AC-12 Conflicto posterior | **Pasa (Google simulado)** | Auto: alerta, sin cancelar ni mover; un solo aviso. |
| AC-13 Archivo y eliminación | **Pasa** | Auto `AdminActionsTest`; Nav archivar/restaurar y bloqueo con dependencias explicadas. |
| AC-14 Pago | **Pasa** | Auto: no existe edición ni borrado de pagos; reverso con motivo, enlazado, una sola vez. |
| AC-15 Cliente duplicado | **No probado (pospuesto, Salida B)** | No se implementó la detección por nombre ni la fusión. |
| AC-16 CSV | **No probado (pospuesto, Salida B)** | No se implementó la importación. |
| AC-17 Actualización | **Pasa (local)** | Auto beta.1 → beta.2 y 0.1.1 → 3; Nav con datos locales; ZIP en PHP 7.4. Falta con una copia de la base productiva en el subdominio. |
| AC-18 Dispositivos | **Pasa en lo probado** | Nav 360 px sin desplazamiento horizontal ni controles recortados; orden de tabulación y casilla con teclado. Sin tableta ni lector de pantalla. |

**Condición de la sección 21 para abrir la preventa:** AC-01 a AC-14, AC-17 y AC-18 pasan localmente, pero **falta la prueba de punta a punta en el subdominio HTTPS** (con Google real, correo y cron). Hasta hacerla, no se debe abrir con datos reales.

## 9. Riesgos conocidos

- Google real, correo real y cron real sin probar (ver sección 7).
- Si el hosting no permite una carpeta fuera de `public_html`, la carga queda deshabilitada salvo que el servidor niegue la URL dentro de uploads (Apache/LiteSpeed con `.htaccess`). En Nginx sin reglas, la verificación fallará y habrá que pedir la carpeta al hosting.
- Un servicio sin contrato publicado no acepta reservas en línea: revisa Contratos después de actualizar.
- Clientes frecuentes: con la Salida B, cada solicitud "frecuente" requiere tu revisión manual antes de aprobar el anticipo.
- Retención automática de comprobantes a 30 días (8.4): no implementada; no se borra ningún comprobante automáticamente.
- Rollback a beta.1 reactiva los problemas de seguridad corregidos.

## 10. Instalación y actualización

Ver `docs/respaldo-y-reversion.md` (respaldo, actualizar, revisar contratos y ajustes) y `docs/guia-google-cloud.md` (OAuth en el subdominio HTTPS, `wp-config.php`, conectar, desconectar, reconectar, revocar y cambiar el destino).

**Requisitos del hosting:** PHP 7.4 o superior (probado en 7.4.33 y 8.3) con `mysqli`, `mbstring`, `sodium`, `openssl` y de preferencia `gd`; WordPress 6.4+ (probado en 7.1.3); MySQL 5.7+ o MariaDB 10.3+ con InnoDB y transacciones; HTTPS con certificado válido; una carpeta escribible fuera de `public_html` para `MO_BOOKING_PRIVATE_DIR`; cron del sistema llamando a `wp-cron.php` cada 5 minutos (recomendado); SMTP autenticado para correo real; límite de subida de PHP ≥ 10 MB.

## 11. Rollback

Ver `docs/respaldo-y-reversion.md`: volver a la beta.1 sin perder datos (el esquema no se revierte; la beta.1 pide confirmar respaldo y solo agrega), o restaurar el respaldo de UpdraftPlus para volver exactamente al estado anterior.

## 12. Archivos y hash

- `mo-booking-studio-0.2.0-beta.2.zip` y `mo-booking-studio-0.2.0-beta.2.zip.sha256`.
- Archivos modificados respecto de 0.2.0-beta.1: ver la lista en `CHANGELOG.md` y `git diff --name-status c735f0a HEAD` en la rama.

## 13. Funciones pospuestas (explícitas)

- **P0 condicional, Salida B**: importación/exportación CSV (11), contactos múltiples y verificación de contactos (10.1), detección de duplicados y fusión con reversión (10.2–10.3), anonimizar desde la ficha (se mantiene el borrador de privacidad de WordPress), verificación por código (OTP) y precio frecuente automático.
- **No P0 de la sección 8.4**: retención y borrado automático de comprobantes rechazados, sustituidos o vencidos a los 30 días (reemplaza tu decisión anterior del botón manual a 90 días; queda para decidir e implementar en la siguiente entrega).
- **Sección 15** (no P0): colores de botón secundario configurables.
- **9.7, acción opcional**: "Adoptar la nueva fecha de Google" (hoy: restaurar desde WordPress, recrear, mantener o ignorar con nota).
- **P1**: reagenda y cancelación autónomas desde el portal; Agenda y Reservas estilo Pixieset/Google Calendar; editor simplificado de disponibilidad y descansos; administración completa de plantillas (crear, retirar, validar variables, vista previa); perfil ampliado con enlace y PIN de galería; panel general de ingresos.
- **P2**: Proyectos, postproducción automática, fecha límite de entrega, estados y atrasos, tipos de proyecto.
- **P3**: Finanzas 2027, Google Sheets, reportes avanzados, galerías hospedadas.
