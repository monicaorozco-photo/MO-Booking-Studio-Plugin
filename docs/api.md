# Contrato REST `mo-booking/v1`

Base: `/wp-json/mo-booking/v1/` (o `?rest_route=/mo-booking/v1/` con enlaces permanentes simples).

- Todas las respuestas llevan `Cache-Control: no-store` y `Referrer-Policy: no-referrer`, y no envían cabeceras CORS.
- Los errores tienen la forma `{ code: "mo_<código>", message: "texto en español", data: { status, field?, quote? } }`.
- Los mensajes de error nunca incluyen SQL, rutas del servidor ni credenciales.

| Código HTTP | Significado |
|---|---|
| 400 | Datos inválidos (`data.field` indica el campo) |
| 401 | Falta la sesión del portal |
| 403 | Token o CSRF inválido |
| 404 | No existe o no está disponible |
| 409 | Conflicto de cupo (`slot_unavailable`), versión, precio (`quote_changed`, con `data.quote`) o clave idempotente reutilizada con otros datos |
| 410 | Cotización o enlace vencidos |
| 413 | Archivo demasiado grande |
| 415 | Tipo de archivo no permitido |
| 429 | Límite de solicitudes por IP |
| 503 | Carga de comprobantes deshabilitada |

## Públicas (sin datos personales)

| Método y ruta | Entrada | Respuesta y efectos |
|---|---|---|
| `GET /services` | — | Servicios publicados. |
| `GET /services/{slug}` | — | Datos públicos del servicio, textos de políticas y anticipo. |
| `GET /services/{slug}/availability?from=AAAA-MM-DD&to=AAAA-MM-DD` | Máximo 62 días | `days[]`: `{date, status: available\|sold_out\|closed, available, total, price, price_txt, special, saving_txt, notices[], slots[]: {start (UTC ISO), time (local), available, label}}`. No incluye nombres ni detalles de otras citas. Concilia vencimientos. Límite: 120 por IP cada 10 min. |
| `POST /services/{slug}/quote` | `{start, participants, pets, extras: {clave: cantidad}}` | `{quote_id, expires_at, lines[], total, deposit, balance, date_txt, time_txt, timezone, notices[], hold_hours}`. Valida el horario y calcula el precio en el servidor. Límite: 60 cada 10 min. |
| `POST /services/{slug}/bookings` | `{quote_id, idempotency_key, name, email, phone, phone_country: MX\|US, answers: {clave: valor}, accept_terms: true, whatsapp_consent, frequent_claim}` | 201 `{reference, portal_url, csrf, booking: vista del portal}`. Crea la reserva y la ocupación, retiene el horario y abre la sesión del portal (cookie). Reintentar con la misma clave devuelve la misma reserva (200, `replayed: true`). Límite: 8 cada 10 min. |

## Portal (cookie `mo_booking_portal`)

| Método y ruta | Autorización | Efecto |
|---|---|---|
| `POST /portal/session` | `{token}` del enlace | Crea una sesión de 2 h (cookie HttpOnly, SameSite=Strict) y devuelve `{csrf, booking}`. |
| `GET /portal/booking` | Cookie | `{csrf (nuevo), booking}`. La vista no incluye notas internas ni datos de otras reservas. |
| `POST /portal/proofs` | Cookie + cabecera `X-MO-CSRF`; multipart `file` | Guarda el comprobante. Si llega antes del vencimiento, la reserva pasa a "Pago en revisión" y el horario sigue retenido. Si llega tarde, se guarda para conciliación manual sin recuperar el horario. |
| `POST /portal/logout` | Cookie + `X-MO-CSRF` | Cierra la sesión. |

## Administración

Las acciones del panel van por `admin-post.php?action=mo_booking&do=<acción>`. Cada una exige su capacidad (ver `src/Admin/Admin.php::ACTIONS`) y un nonce propio. Las operaciones de negocio están en `src/Application/*`; las reservas manuales y las públicas usan las mismas validaciones.
