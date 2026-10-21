# Guía: conectar Google Calendar

Lo que ya hiciste en Google Cloud (proyecto, API de Calendar activada) sirve tal cual. Faltan cuatro cosas: la pantalla de consentimiento, el cliente web, tres líneas en `wp-config.php` y conectar desde el panel.

> Haz todo esto primero en **LocalWP** con calendarios de prueba. Cuando las pruebas de `docs/pruebas-google.md` salgan bien, repites los pasos 3 a 5 en el sitio real.

## 1. Pantalla de consentimiento (Google Auth Platform)

1. En console.cloud.google.com elige tu proyecto → **Google Auth Platform** (o "Pantalla de consentimiento de OAuth").
2. **Información de la app:** nombre "MO Booking Studio", tu correo de soporte.
3. **Público:** *Externo*.
4. **Acceso a datos → Agregar o quitar permisos.** Pega estos tres y guarda:
   - `https://www.googleapis.com/auth/calendar.calendarlist.readonly`
   - `https://www.googleapis.com/auth/calendar.freebusy`
   - `https://www.googleapis.com/auth/calendar.events`
5. **Público → Publicar app** (pasa a "En producción").
   - En modo "Prueba", Google borra el acceso cada 7 días y tendrías que reconectar.
   - Como la app no está verificada, al conectar Google mostrará "Google no verificó esta app". Es normal para una app que solo usas tú: pulsa **Avanzado → Ir a MO Booking Studio**.

## 2. Ver la dirección de retorno en el plugin

1. Instala la versión 0.2 del plugin (ver `docs/respaldo-y-reversion.md` antes de actualizar el sitio real).
2. Ve a **Reservas → Google Calendar**. En "1. Credenciales" aparece la **dirección de retorno**, por ejemplo:
   `https://tu-sitio.com/wp-admin/admin-post.php?action=mo_booking_google_callback`
3. **LocalWP:** Google no acepta dominios `.local`. En LocalWP, en *Preferences → Advanced → Router mode*, elige **localhost**. El sitio queda como `http://localhost:10005` (el número cambia) y esa es la dirección que verás en el panel.

## 3. Crear el cliente web

1. Google Auth Platform → **Clientes → Crear cliente**.
2. Tipo: **Aplicación web**. Nombre: "MO Booking Studio – LocalWP" (luego otro para el sitio real).
3. **URI de redireccionamiento autorizados → Agregar URI**: pega exactamente la dirección del paso 2, sin espacios.
4. Crear. Copia el **ID de cliente** y el **secreto del cliente**. Guarda el secreto en un lugar seguro; Google ya no lo vuelve a mostrar completo.

## 4. Tres líneas en `wp-config.php`

El panel te muestra las líneas que faltan, con una clave `MO_BOOKING_SECRET` ya generada al azar. Cópialas en `wp-config.php` **antes** de la línea `/* That's all, stop editing! */`:

```php
define( 'MO_BOOKING_GOOGLE_CLIENT_ID', 'tu-id.apps.googleusercontent.com' );
define( 'MO_BOOKING_GOOGLE_CLIENT_SECRET', 'tu-secreto' );
define( 'MO_BOOKING_SECRET', 'la-clave-de-64-caracteres-que-te-dio-el-panel' );
```

- En LocalWP: botón derecho en el sitio → *Go to site folder* → `app/public/wp-config.php`.
- En el hosting: Administrador de archivos → `public_html/wp-config.php`. Descarga una copia antes de editarlo.
- `MO_BOOKING_SECRET` cifra los permisos de Google guardados en la base de datos. **Usa una distinta en cada sitio y no la cambies**: si cambia, solo hay que volver a conectar Google. Los enlaces privados ya enviados a tus clientes no se ven afectados.
- Nunca compartas estas líneas ni las subas a GitHub.

## 5. Conectar y elegir calendarios

1. Recarga **Reservas → Google Calendar**. "1. Credenciales" debe decir que se encontraron.
2. **Conectar con Google** → elige tu cuenta → acepta **las tres casillas** de permisos.
3. En "3. Calendarios":
   - **Leer ocupación**: marca todos los calendarios cuyos eventos deben bloquear horarios (personal, familia, otros trabajos).
   - **Destino**: el calendario donde el plugin escribe las reservas. Recomendado: uno nuevo y dedicado, por ejemplo "MO Reservas". Los eventos que crea el plugin ahí no bloquean; cualquier otro evento que pongas ahí sí.
4. **Guardar calendarios.** Desde ese momento:
   - Cada reserva pendiente crea un evento interno "MO PENDIENTE · MO-XXXX" (privado, sin invitados).
   - Al aprobar el anticipo, el interno cambia a "MO CONFIRMADA" y se crea el evento del cliente "Sesión con {tu marca}", con invitación de Google al correo que escribió la persona.
   - Las reservas en línea consultan Google en cada cotización y reserva. **Si Google no responde, la reserva en línea se detiene** con un aviso y solo tú puedes crear reservas manuales (casilla "Crear la reserva sin verificar Google", que queda registrada como excepción).

## Si algo sale mal

| Mensaje | Qué hacer |
|---|---|
| "redirect_uri_mismatch" en Google | La URI del cliente web no es idéntica a la del panel (revisa `http`/`https`, puerto y que no sobre una `/`). |
| "Google no entregó acceso permanente" | En tu cuenta de Google → Seguridad → Apps de terceros, quita "MO Booking Studio" y vuelve a conectar. |
| "Faltan permisos al conectar" | Vuelve a conectar y marca todas las casillas. |
| Estado "Acceso revocado" | Se pausaron las sincronizaciones sin perder nada. Pulsa **Volver a conectar**; los pendientes se envían solos. |
| Trabajos fallidos en "4. Estado" | Lee el error y pulsa **Reintentar**. |
