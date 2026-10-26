# Entrega 0.2.0-beta.4 (lanzamiento de la preventa)

Esta versión **no cambia la base de datos** (sigue en el esquema 4), así que no pide confirmar respaldo al actualizar. Igual, haz un respaldo con UpdraftPlus antes de subirla.

## Cómo actualizar
1. Respaldo con UpdraftPlus (base de datos y plugins).
2. Plugins → Añadir nuevo → Subir plugin → `mo-booking-studio-0.2.0-beta.4.zip` → **Reemplazar**.
3. Revisa los pasos de "Configurar después de actualizar".

## Configurar después de actualizar

### 1. Títulos en Google Calendar (por servicio)
Servicios → Editar el servicio:
- **Título en tu Google Calendar:** por ejemplo `HM: {cliente} · {estado}` → "HM: Ana López · Por confirmar", que cambia a "En revisión" y "Confirmada".
- **Título de la invitación a la clienta:** por ejemplo `Holiday Mini: {cliente}`.
- Códigos: `{cliente}` `{servicio}` `{estado}` `{referencia}` `{negocio}`.

Los eventos que ya existen se actualizan solos en la siguiente sincronización.

### 2. Un solo evento en tu calendario
1. En Google Calendar crea un calendario **"MO Invitaciones"** (Otros calendarios → + → Crear calendario).
2. En WordPress: Reservas → Google Calendar → "3. Calendarios" → **Invitaciones a clientas** → elige "MO Invitaciones" → Guardar.
3. En Google Calendar, a la izquierda, **desmarca** "MO Invitaciones" para ocultarlo.

Tú ves solo el evento interno (con el enlace al panel) y la clienta recibe su invitación normal. Las invitaciones que ya existían se mueven solas a ese calendario, sin volver a enviarse. El evento interno sigue bloqueando el horario.

### 3. Vista de reserva con Elementor
Edita tu página **"Mi reserva"** con Elementor, cambia el shortcode `[mo_booking_portal]` por el widget **"MO Vista de reserva"** y usa la pestaña **Estilo**. En el editor se ve una reserva de ejemplo. El shortcode sigue funcionando si prefieres no tocar la página.

En el widget **"MO Booking Studio"** (formulario), la pestaña Estilo ahora tiene **Calendario y horarios**: colores de días con horarios, preventa, sin horarios, llenos y de los horarios.

### 4. Textos nuevos en Ajustes
- **Texto de horario ocupado** (por ejemplo "No disponible").
- **Aviso antes del contrato.**
- **Casilla de avisos** (texto y si es obligatoria; viene obligatoria).
- **Texto junto al comprobante:** ya existía; recuerda llenar **Número de WhatsApp del negocio** para que aparezca tu número.

### 5. Correos con formato y WhatsApp
- Notificaciones → cada aviso tiene un editor con negritas, cursivas, títulos, listas y enlaces. Los correos salen con formato y con una copia en texto simple.
- Cada aviso tiene **Texto para WhatsApp (opcional)**. En WhatsApp: `*negrita*`, `_cursiva_`, `~tachado~`. Si lo dejas vacío, se usa el texto del correo (las negritas se convierten solas).
- Al abrir WhatsApp desde la reserva, el mensaje ya conserva los saltos de línea y los emojis.

### 6. Correo que llega a la bandeja (recomendado)
1. Instala **WP Mail SMTP** (gratis) y conéctalo a tu correo (Gmail, Outlook o el correo de tu hosting).
2. En WP Mail SMTP → Herramientas → **Prueba de correo**: envía una prueba a un Gmail y a un Hotmail tuyos y revisa que lleguen a la bandeja principal.
3. En Reservas → Ajustes → Correo, modo **Envío real** y tu nombre y correo como remitente.

## Qué cambió para la clienta
- Al enviar la solicitud llega directo a **su página de reserva** (la misma que abre después con su enlace), con un aviso de bienvenida. Se quitó la pantalla "Listo" aparte.
- **Ubicación e indicaciones** aparecen solo cuando su anticipo está confirmado (también en la página para imprimir). Antes ve "Te lo compartiremos aquí en cuanto confirmemos tu anticipo".
- Ve el **contrato que aceptó** (sin número de versión), sus **comprobantes** (puede abrirlos) y sus **pagos recibidos**.
- El aviso "Recibimos tu comprobante" aparece dentro del recuadro del comprobante.
- En el formulario: aviso antes del contrato, sin "versión 2", horarios apartados con tu texto ("No disponible") y casilla de avisos obligatoria.
- Las reglas de cancelación ya no se le muestran (quedan como control interno).

## Qué cambió en el panel
- **Papelera en reservas vigentes:** se cancelan solas **sin avisar a la clienta** (ni correo ni cancelación de Google), se libera el horario y el anticipo queda "por resolver". Para avisarle, usa "Cancelar" dentro de la reserva.
- **Cliente frecuente con teléfono compartido:** si su correo es de una sola clienta, recibe el descuento aunque el teléfono esté en otro expediente. La reserva lleva la marca **"Revisar teléfono"**.

## Pruebas realizadas
- 209 pruebas automáticas en PHP 7.4 y 8.3 (nuevas en `tests/Integration/Beta4Test.php` y en `GoogleCalendarTest.php`).
- Navegador: reserva completa en teléfono hasta su página de reserva, comprobante y su vista segura, panel, clientes y papelera, plantillas con editor de formato, enlace de WhatsApp con saltos de línea.
- No se pudo probar el widget dentro de Elementor (no está instalado en el sitio de pruebas); los controles usan las mismas clases que el formulario.
