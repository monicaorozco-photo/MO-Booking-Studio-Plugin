# Entrega Cero: auditoría y plan para la versión 0.2

Fecha: 21 de octubre de 2026. Base: versión 0.1.1 (rama `main`, commit `aa0df20`). Documento de referencia: *Requisitos finales para implementación con Claude Code* (21 oct 2026) y las decisiones de Mónica del mismo día.

**Hasta que apruebes este informe no se modifican datos ni el comportamiento comercial.**

---

## 1. Cómo funciona hoy (v0.1.1)

| Aspecto | Estado actual |
|---|---|
| Código | 54 archivos PHP, unos 10,800 renglones con JS y CSS. Namespace `MOBookingStudio`, autoloader propio, sin Composer ni librerías externas en producción. |
| Capas | `Domain` (reglas puras: disponibilidad, precios, políticas, estados, movimientos), `Application` (operaciones), `Infrastructure` (base de datos, archivos, tokens, ajustes), `Rest`, `Admin`, `Frontend`, `Elementor`. |
| Versiones | WordPress ≥ 6.4 (probado con 7.1.3) y PHP ≥ 7.4 (probado con 7.4.33 y 8.3.6). Tu hosting: PHP 7.4.33, LiteSpeed, Elementor 4.2.2, Elementor Pro 4.0.4, Gravity Forms, WP Fastest Cache y UpdraftPlus. |
| Base de datos | 17 tablas `wp_mo_*`, esquema versión 1 en la opción `mo_booking_schema_version`. Las migraciones se ejecutan con `dbDelta` al activar el plugin **y** cuando cambia la versión (`plugins_loaded`), así que no dependen solo de la activación (UPD-02 ✅). |
| Fechas | Se guardan en UTC. La zona propia del plugin es America/Ciudad_Juarez, con equivalente America/Denver si PHP no la conoce. |
| Estados | `pending_payment`, `payment_review`, `correction_requested`, `pending_approval`, `confirmed`, `completed`, `expired`, `rejected`, `cancelled`, `no_show`. Solo se permiten transiciones definidas, con actor, fecha, estado anterior y motivo en `wp_mo_audit`. |
| Cupos | Bloqueo por recurso y día dentro de una transacción. Probado con dos procesos simultáneos. |
| Tareas | WP-Cron cada 5 minutos (`mo_booking_process_queue`): vencimientos y cola de correos. Comando WP-CLI `wp mo-booking process`. Los vencimientos también se revisan en cada consulta y reserva. |
| Permisos | 10 capacidades propias (`mo_manage_settings`, `mo_manage_bookings`, `mo_review_payments`, etc.) y el rol "Colaborador de estudio". Cada acción del panel valida capacidad y nonce. |
| Shortcodes | `[mo_booking service="slug"]` y `[mo_booking_portal]`. Widget de Elementor "MO Booking Studio". |
| API REST | `mo-booking/v1`: services, availability, identify, quote, bookings, portal/session, portal/booking, portal/proofs, portal/logout. Sin CORS y con `no-store`. |
| Enlace privado | Token HMAC de 256 bits; en la base solo se guarda su hash. Se cambia por una cookie HttpOnly SameSite=Strict y las operaciones del portal exigen CSRF. Se puede rotar. |
| Comprobantes | JPG, PNG y PDF hasta 10 MB. Se comprueban extensión, MIME real y firma. Nombre aleatorio, carpeta privada verificada, hash SHA-256 y aviso de duplicados. |
| Integraciones | Correo (`wp_mail`, en simulación) y WhatsApp manual. **No hay Google Calendar.** |
| Pruebas | 105 automáticas (PHP 7.4 y 8.3) y 5 guiones de navegador. |
| Datos sensibles | Contacto de clientes, respuestas, comprobantes (carpeta privada) y datos bancarios en Ajustes. Hoy no hay secretos que cifrar; con Google sí habrá (tokens OAuth). |

## 2. Comparación con los requisitos nuevos

✅ ya cumple · 🟡 cumple en parte, hay que ajustar · ❌ falta

| Requisito | Estado | Qué falta |
|---|---|---|
| Fuente de verdad en WordPress (DEC-07) | ✅ | Mantenerlo al integrar Google. |
| Estados con transiciones y auditoría | 🟡 | Agregar "En revisión" (`under_review`); dejar de crear "Pendiente de aprobación". |
| Idempotencia de reservas, pagos y avisos | ✅ | Extenderla a los trabajos de Google. |
| Google Calendar (CAL-01 a CAL-11, SYNC-01 a 07) | ❌ | Todo el módulo. |
| Pago a tiempo detiene el vencimiento (DEC-02, AC-PAY-01) | ✅ | — |
| Carga tardía rechazada (AC-PAY-02) | 🟡 | Hoy se guarda para conciliar. Cambiará al rechazo con el texto que decidiste. |
| Corrección: una sola, 2 horas (DEC-01) | 🟡 | Hoy dura 12 h y no tiene límite de veces. |
| Motivos de corrección de una lista (sección de revisión) | ❌ | Hoy el motivo es texto libre y se envía al cliente. |
| Versiones de comprobante (PAY-08) | 🟡 | Ya se conservan todos los archivos. Falta numerar las versiones y ligar cada sustitución a su corrección. |
| No abrir archivos en el navegador del administrador (PAY-07) | 🟡 | Hoy las imágenes se ven en línea, aisladas (sandbox). Ver la propuesta P2. |
| Límite por token (PAY-04) | 🟡 | Hoy el límite es por IP. Se agrega por token. |
| Contrato con editor, versiones y copia congelada (CON-01 a 06) | 🟡 | Hoy son condiciones en texto plano por servicio, guardadas como huella en `consent`. Falta el editor con formato, borrador y publicado, la copia completa congelada y el acordeón. |
| Contactos secundarios (DEC-04) | ❌ | Hoy cada cliente tiene un correo y un teléfono. |
| Cola de posibles duplicados (DEC-03, AC-CSV-03) | 🟡 | Hoy se marca "revisar"; no hay sugerencias por nombre ni fusión con reversión. |
| Importación y exportación CSV (CSV-01 a 07) | ❌ | Todo el módulo. |
| Cifrado de secretos (SEC-05) | ❌ | Necesario para los tokens de Google. |
| Respaldo antes de migrar (UPD-01) | 🟡 | El plugin no puede respaldar el sitio. Se hará con UpdraftPlus (guion) y el plugin pedirá confirmar el respaldo antes de migrar. |
| No destrucción y rollback (UPD-03 y 04) | ✅ | Las migraciones de 0.2 solo agregan. Ver la sección 5. |
| Exportador y borrador de privacidad (SEC-07) | ✅ | Ampliarlo a contactos y contratos. |

## 3. Correspondencia de estados

Para que **volver a la versión 0.1.1 sea posible sin restaurar la base de datos**, los valores internos que ya existen no cambian de nombre; solo cambia lo que ves en pantalla.

| Estado del documento | Valor interno | Cambio |
|---|---|---|
| pending_payment | `pending_payment` | Sin cambio. Vence 12 h después de crearse la reserva. |
| proof_received | `payment_review` | Se muestra como "Comprobante recibido". |
| under_review | `under_review` (nuevo) | Se activa cuando abres el comprobante o pulsas "Revisar". |
| correction_requested | `correction_requested` | Plazo de 2 h y una sola sustitución. |
| confirmed | `confirmed` | Aprobar el anticipo completo confirma siempre (decisión 2). |
| expired / rejected / cancelled / completed | iguales | Sin cambio. |
| — | `no_show` | Se conserva (decisión 3). |
| — | `pending_approval` | Ya no se crea. Las reservas existentes se pasan a `confirmed` **sin enviar avisos** y queda registrado en el historial. Los servicios sin anticipo se confirman al reservar. |

**Pago parcial (tu decisión 4):** registras el monto recibido. Si es menor que el anticipo, el pago queda registrado, la reserva vuelve a "Pendiente de pago" y conserva **la hora límite original** (12 h desde que reservó). Si es mayor, se confirma y el excedente se descuenta del saldo.

## 4. Diseño de Google Calendar

- **Sin librerías de Google.** Llamadas directas a la API con las funciones HTTP de WordPress. La librería oficial pide PHP 8 y es grande (SEC-08).
- **Permisos (scopes):** `calendar.calendarlist.readonly`, `calendar.freebusy` y `calendar.events`.
- **Secretos:**
  - El Client ID y el Client secret van en `wp-config.php`.
  - Los tokens de acceso se guardan cifrados (libsodium) con la clave `MO_BOOKING_SECRET` de `wp-config.php`, que nunca vive en la base de datos.
  - Nunca se muestran ni se registran.
- **Disponibilidad:**
  - Ocupación interna, más FreeBusy de los calendarios que elijas, más los eventos del calendario destino que **no** sean del plugin.
  - FreeBusy no permite excluir eventos, por eso el calendario destino se consulta aparte.
  - El calendario mensual usa una caché de unos minutos. Al cotizar y al enviar la reserva se consulta de nuevo a Google.
  - Si Google no responde, la reserva en línea se detiene con un aviso y tú puedes crearla a mano con advertencia (decisión 8).
- **Eventos:**
  - Identificador determinista (no se duplica tras un reintento).
  - Marca privada de propiedad del plugin, para borrar solo lo que el plugin creó.
  - Se guarda el etag de cada evento.
- **Evento interno:**
  - Se crea al reservar como "MO PENDIENTE MO-XXXXXX" y cambia a "MO CONFIRMADA" al aprobar el anticipo.
  - Ocupado, privado, sin invitados y sin notificar.
  - Contiene nombre, teléfono, personas, mascotas, estado del pago, vencimiento y enlace al panel (que exige iniciar sesión).
- **Evento del cliente:**
  - Se crea solo al aprobar el anticipo.
  - Transparente (no duplica el bloqueo) y con la invitación al correo de la reserva.
  - Contiene únicamente fecha, hora, ubicación e indicaciones.
- **Cola de trabajos** en una tabla persistente:
  - reintentos con espera creciente y aleatoria;
  - los errores permanentes no se reintentan;
  - si se revoca el acceso, se pausan las escrituras sin borrar nada y se muestra un aviso para reconectar;
  - el aviso "Evento ya eliminado" (código 410) se trata como terminado.
- **Conflictos:**
  - Revisión periódica de las reservas próximas.
  - Si un evento externo se encima con una reserva, se marca y te avisa (en el panel y por correo, agrupado) sin cancelar ni mover nada.
  - Acciones disponibles: mantener, reprogramar, cancelar, ignorar hasta una fecha o marcar como resuelto.
- **Panel de diagnóstico:**
  - cuenta conectada, calendarios de lectura y calendario destino;
  - última sincronización y trabajos pendientes con sus errores;
  - botones de reconectar y "conciliar ahora".

**Tablas nuevas:** `mo_sync_jobs`, `mo_calendar_events`, `mo_conflicts`, `mo_contracts`, `mo_client_contacts`, `mo_duplicate_suggestions`, `mo_merges`, `mo_import_batches` y `mo_import_rows`.

**Columnas nuevas en reservas:** `deadline_at` (hora límite original), `corrections_used`, `contract_id`, `contract_hash`, `sync_state` y `conflict_state`.

## 5. Migración, respaldo y reversión

1. **Antes:** respaldo completo con UpdraftPlus (base de datos, plugins y uploads) y descarga del archivo. Comprobar que se puede abrir. Guion paso a paso en `docs/respaldo-y-reversion.md`.
2. **Migración a esquema 2:**
   - solo agrega tablas y columnas, sin borrar ni renombrar;
   - se puede repetir sin efectos dobles (cada paso revisa si ya se hizo).
   
   Datos que se transforman:
   - `pending_approval` → `confirmed`, sin avisos y con historial;
   - el correo y el teléfono de cada cliente se copian a `mo_client_contacts` como contactos principales no verificados;
   - las condiciones de cada servicio se convierten en la versión 1 publicada de su contrato. El texto general de Ajustes, si existe, se agrega al final de cada una. El campo viejo no se borra.
   - `deadline_at` de reservas existentes = creación + horas de retención.
3. **Volver a 0.1.1 sin tocar datos:** se puede, porque 0.1.1 ignora tablas y columnas nuevas. Hay una excepción: las reservas que estén en `under_review` en ese momento. 0.1.1 no conoce ese estado; el guion incluye una consulta que las regresa a `payment_review` antes de reinstalar.
4. **Volver a 0.1.1 con datos:** restaurar el respaldo de UpdraftPlus. Después se borran a mano en Google los eventos creados durante la prueba; todos llevan el prefijo "MO".
5. **Prueba de migración:** se ensaya aquí con una copia representativa de datos 0.1.1 (reservas en todos los estados), se repite la migración y se prueba la reversión. Tú repites el ensayo en LocalWP y en staging.

## 6. Propuestas que necesitan tu aprobación

| # | Propuesta | Por qué |
|---|---|---|
| P1 | **Un solo enlace privado** para ver el estado y subir el comprobante. La carga solo se permite mientras está abierta la ventana (12 h, o 2 h de corrección); después el mismo enlace solo muestra el estado. | Cumple PAY-02 y PAY-03 en efecto y es más simple para tu clienta que dos enlaces. El documento pide caducar el "token de carga"; aquí caduca la *capacidad de carga*, del lado del servidor. |
| P2 | **Imágenes:** el panel muestra una copia re-procesada por el servidor, que elimina cualquier contenido oculto, nunca el archivo original. **PDF:** solo descarga. | PAY-07 pide no abrir archivos en el navegador del administrador sin antivirus. Tu hosting no tiene antivirus integrado; queda documentado como limitación. |
| P3 | La invitación de Google y el título del evento usan **el nombre comercial del servicio** ("Sesión con Clics con Amor Fotografía") en lugar del texto fijo "MO Booking Studio". | Tu clienta no conoce el nombre del plugin. |
| P4 | Si registras un pago parcial **cuando la hora límite ya pasó** (llegó a la hora 11 y lo revisas en la hora 13), la reserva vence en ese momento y el horario se libera. El dinero recibido queda registrado como "por resolver" (devolución o abono a una nueva reserva) y lo decides tú. | Es la consecuencia de tu decisión 4. Así el dinero no se pierde en el sistema. |
| P5 | Motivos de corrección iniciales, editables: "Archivo ilegible", "No se identifica el monto", "Referencia incorrecta", "Archivo equivocado" y "Monto incompleto". Tu nota libre queda solo como nota interna. | Requisito de la sección de revisión. |
| P6 | Plazo de entrega. La semana alcanza para Google Calendar y pagos (Entrega 1). Contrato, CSV y fusiones (Entrega 2) son mucho trabajo para un solo día. Propongo entregar Google y pagos el viernes 23 y contrato, CSV y duplicados el domingo 25. Si algo no pasa las pruebas, la versión se queda para staging y no se publica, como dice el documento. | Prioridades de la sección "si el tiempo no alcanza". |

## 7. Cómo se probará

- **Aquí:**
  - pruebas automáticas contra un **Google simulado**: eventos, FreeBusy, errores 401, 410, 429 y 500, revocación, reintentos, idempotencia y cambio de horario;
  - concurrencia con procesos reales;
  - PHP 7.4 y 8.3;
  - ensayo de migración y de reversión;
  - pruebas de seguridad: CSRF, acceso entre reservas, XSS en contrato y CSV, archivos hostiles;
  - recorrido en navegador.
- **Tú, en LocalWP** con tu cuenta y tus calendarios de prueba: el guion `docs/pruebas-google.md` cubre de AC-CAL-01 a AC-CAL-10 con casillas para marcar.
- **Tú, en staging de tu hosting:** cron real, correo, límites de carga, caché, firewall y migración con copia de datos.

## 8. Archivos que cambiarán

| Archivos | Cambio |
|---|---|
| `src/Infrastructure/Schema.php` y `Installer.php` | Migraciones del esquema 2, paso a paso. |
| `src/Domain/BookingStatus.php` | Estado `under_review`; `pending_approval` queda solo como compatibilidad. |
| `src/Application/Bookings.php` y `Proofs.php` | Hora límite original, corrección única de 2 h, rechazo tardío, motivos de lista, versiones de comprobante. |
| `src/Infrastructure/Crypto.php` | Nuevo: cifrado de secretos. |
| `src/Integrations/Google/*` | Nuevo: OAuth, cliente HTTP, FreeBusy, eventos, cola, conflictos. |
| `src/Application/Contracts.php` y `src/Admin/Pages/ContractsBox` | Nuevo: contrato por servicio con borrador, publicación y vista previa. |
| `src/Application/ClientContacts.php`, `Duplicates.php`, `CsvImport.php` | Nuevos: contactos, duplicados, importación y exportación. |
| `src/Admin/Pages/*` | Página "Google Calendar", cola de duplicados, importar/exportar, ajustes de motivos, aviso de zona horaria. |
| `assets/js/booking.js` y `src/Elementor/BookingWidget.php` | Acordeón del contrato, textos editables de la pantalla inicial. |
| `docs/*` | Guías de Google Cloud, pruebas, respaldo y reversión. |

---

**Para empezar necesito tu "sí"** a este informe y a las propuestas P1 a P6, o tus cambios. A partir de ahí empiezo con la Entrega 1 (migraciones y Google Calendar) en la rama `v0.2-google-calendar`.
