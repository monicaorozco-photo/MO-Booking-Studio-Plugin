# Modelo de datos

Tablas con el prefijo de WordPress más `mo_`. La versión del esquema se guarda en `mo_booking_schema_version`. Las migraciones usan `dbDelta`, que es idempotente. Todas las fechas se guardan como DATETIME en UTC; cada reserva guarda además la zona IANA del negocio.

| Tabla | Contenido |
|---|---|
| `mo_resources` | Agendas compartidas. Se crea "Agenda principal" al activar. |
| `mo_categories` | Categorías para reportes, con identificador estable (renombrar no rompe los vínculos). |
| `mo_services` | Servicio: slug, estado, recurso, categoría, `config` (JSON con duración, márgenes, personas, precio, anticipo, extras, preguntas y avisos) y versión. |
| `mo_availability` | `weekly` (día de la semana y horas), `date` (ventanas que reemplazan el horario semanal), `closed` (día cerrado), `block` (bloqueo en UTC). |
| `mo_clients` | Contacto original y normalizado (`email_norm`, `phone_e164`), categoría manual, sesiones previas y completadas, marca de revisión. El teléfono no es único (puede ser de una familia). |
| `mo_bookings` | Referencia pública aleatoria (`MO-XXXXXX`), estado, inicio y fin UTC, zona, cotización congelada (JSON), políticas aceptadas (JSON con versión), respuestas, contacto, consentimiento, `expires_at`, `correction_until`, `idempotency_key` única, `token_nonce`, `version` (bloqueo optimista). |
| `mo_occupancies` | Tiempo ocupado del recurso, incluidos los márgenes: inicio y fin UTC, `expires_at` (retención) y `released_at`. |
| `mo_calendar_locks` | Una fila por recurso y día UTC para serializar transacciones. |
| `mo_quotes` | Cotizaciones con clave, datos, huella de precios y vencimiento. |
| `mo_price_rules` | Tarifas finales y promociones: prioridad, condiciones (JSON) y acumulable. |
| `mo_policies` | Políticas versionadas por servicio (0 = general) y acción. `is_current` marca la vigente. |
| `mo_payments` | Movimientos inmutables con clave idempotente única, comprobante, fecha de recepción, categoría y `reverses_id`. |
| `mo_proofs` | Comprobante: nombre aleatorio en almacenamiento privado, MIME, tamaño, SHA-256, estado de revisión y marca de recepción tardía. |
| `mo_access_tokens` | Hash del token de enlace, alcance, vencimiento y revocación. |
| `mo_portal_sessions` | Hash de la sesión y del CSRF, reserva y vencimiento. |
| `mo_notifications` | Cola: evento, canal, destinatario, estado, intentos, `send_after`, `valid_until`, `dedupe_key` única, bloqueo de tarea. |
| `mo_sync_jobs` | Cola de Google: una fila pendiente por reserva (`dedupe_key`), `revision`, `force_sync`, intentos, próxima ejecución, último error HTTP e identificador de solicitud. |
| `mo_calendar_events` | Eventos vinculados por reserva y tipo (`internal`/`client`): calendario, id determinista, etag, estado, huella del contenido, cambio externo detectado e `invited_at`. |
| `mo_conflicts` | Empalmes con Google: reserva, calendario, bloque ocupado, estado (abierto, mantener, ignorado hasta, resuelto, desaparecido), nota y quién lo resolvió. |
| `mo_audit` | Bitácora: actor, entidad, acción, datos (sin secretos) y fecha. |

Esquema 2 (v0.2) agrega a `mo_bookings`: `deadline_at` (hora límite original de pago), `corrections_used`, `sync_state` y `conflict_state`; a `mo_proofs`: `version` y `client_reason`. Pasos de datos registrados en `mo_booking_migrations`. Opciones: `mo_booking_google` (conexión; tokens cifrados), `mo_booking_site_key` (marca de propiedad de eventos), `mo_booking_token_key` (fuente fija de la clave de enlaces).

Esquema 3 (0.2.0-beta.2) agrega a `mo_bookings`: `children`, `adults`, `important_info`, copia del contrato (`contract_id`, `contract_version`, `contract_title`, `contract_body`, `contract_hash` SHA-256, `contract_accepted_at`), `invite_override`, `cancel_outcome` y `archived_at`; a `mo_clients`: `archived_at`. Tablas `mo_contracts` (plantilla, borrador, versión vigente) y `mo_contract_versions` (versiones publicadas inmutables). Las respuestas de preguntas guardan etiqueta, tipo, opciones y valor. La entrega rápida es el extra `entrega-rapida` dentro de la cotización congelada.

Desactivar el plugin no borra nada. Desinstalarlo solo borra los datos si la opción está marcada en Ajustes.
