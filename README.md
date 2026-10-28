# MO Booking Studio

Plugin de WordPress para reservas y administración de un estudio fotográfico: servicios, disponibilidad, tarifas, reservas con anticipo por transferencia, comprobantes privados, portal del cliente y panel de administración.

**Versión 0.2.0-beta.4 (versión de lanzamiento de la preventa).** Lee primero [`docs/entrega-beta4.md`](docs/entrega-beta4.md), [`docs/entrega-beta3.md`](docs/entrega-beta3.md) y [`docs/entrega-preventa-beta2.md`](docs/entrega-preventa-beta2.md) (alcance, criterios de aceptación y pruebas pendientes), [`docs/respaldo-y-reversion.md`](docs/respaldo-y-reversion.md) antes de actualizar y [`docs/guia-google-cloud.md`](docs/guia-google-cloud.md) para Google. Cambios en [`CHANGELOG.md`](CHANGELOG.md).

## Requisitos

- WordPress 6.4 o superior (probado con 7.1.3).
- PHP 7.4 o superior (probado con 7.4.33 y 8.3). En una versión menor, el plugin no se activa y muestra un aviso.
- MySQL o MariaDB con tablas InnoDB (transacciones).
- HTTPS en producción.
- No necesita Composer, Node ni Elementor Pro en el hosting.

## Instalación

1. Haz un respaldo del sitio con UpdraftPlus. De preferencia, prueba primero en una copia (staging).
2. En WordPress ve a *Plugins → Añadir nuevo → Subir plugin* y sube `mo-booking-studio-0.2.0-beta.6.4.zip`.
3. Activa el plugin. Aparece el menú **Reservas**.

El plugin se instala **vacío**. No crea servicios, horarios ni precios y no modifica la zona horaria general del sitio, otros plugins ni los formularios de Gravity Forms.

## Configuración inicial (en este orden)

1. **Reservas → Ajustes**
   - Nombre del negocio, zona horaria (America/Ciudad_Juarez) y correo para recibir avisos.
   - Datos para transferencia.
   - Pulsa **Verificar almacenamiento privado** (ver la sección más abajo). Hasta que la verificación pase, la carga de comprobantes queda deshabilitada.
   - Los correos empiezan en **modo simulación**: se registran pero no se envían. Cámbialos a "Envío real" cuando el correo autenticado esté listo.
2. **Servicios → Agregar servicio**: nombre, duración, márgenes, personas, precio base, anticipo, extras y preguntas. Para aceptar reservas, el estado debe ser *Publicado*.
3. **Disponibilidad**: horario semanal o fechas específicas. Para cargar muchas fechas a la vez, usa *Cargar varias fechas*:
   ```
   2026-11-15 13:00-15:30
   2026-12-06 12:00-15:00, 17:00-19:00
   ```
   La *Vista previa de espacios* muestra cuántos horarios genera cada servicio.
4. **Precios por temporada** (dentro del servicio): fecha límite de preventa y, por rango de fechas, precios de público y de cliente frecuente en preventa y regular. Un solo enlace: al pasar la fecha límite se cobran solos los precios regulares. Para casos especiales existe **Tarifas y descuentos**, con simulador.
5. **Clientes**: da de alta a tus clientas con sus sesiones previas. Al reservar, quien elige "Ya me he tomado fotos con ustedes" y tiene las sesiones necesarias (3 por defecto, se cambia en Ajustes) ve los precios de cliente frecuente.
6. **Políticas**: reprogramación, cancelación e inasistencia, con horas y montos libres y un simulador.
7. **Páginas**: en Ajustes, pulsa *Crear páginas*. Se crean "Reservas" y "Mi reserva" como borradores; revísalas y publícalas.

## Mostrar el formulario

- **Elementor**: busca el widget **MO Booking Studio**, elige el servicio y ajusta colores, tipografía, bordes y espacios en la pestaña *Estilo*. Dentro del editor se muestra una vista previa ficticia que no crea reservas.
- **Shortcode** (funciona sin Elementor): `[mo_booking service="slug-del-servicio"]`
- **Portal del cliente**: una página con `[mo_booking_portal]`, seleccionada en Ajustes como página "Mi reserva". Si no hay página configurada, el portal funciona en `https://tu-sitio/?mo_portal=1`.

## Correo

El plugin envía con `wp_mail`, así que usa el transporte que tenga configurado el sitio. Para enviar desde Gmail se recomienda un plugin SMTP con conexión OAuth de Google (por ejemplo WP Mail SMTP, opción "Google / Gmail"). El plugin no construye su propia conexión OAuth.

En el registro de mensajes, "Aceptado por el transporte" significa que el servidor aceptó el correo. No garantiza que haya llegado a la bandeja de entrada.

## WhatsApp

- **Manual** (predeterminado): desde cada reserva, *Abrir WhatsApp* abre el chat con el texto preparado. Después de enviarlo, márcalo como enviado; abrir el chat no cuenta como envío.
- **Automático**: no está conectado. Requiere un proveedor oficial de WhatsApp Business, plantillas aprobadas y credenciales. Mientras tanto, los mensajes quedan registrados como "sin conexión" y la reserva funciona igual.

## Almacenamiento privado de comprobantes

Lo más seguro es guardar los comprobantes **fuera de la carpeta pública**. Agrega esta línea a `wp-config.php` (antes de "That's all"):

```php
define( 'MO_BOOKING_PRIVATE_DIR', '/home/TU_USUARIO/mo-booking-privado' );
```

Si no la defines, se usa `wp-content/uploads/mo-booking-private` protegida con `.htaccess` (LiteSpeed y Apache lo respetan). En cualquier caso, pulsa **Ajustes → Verificar almacenamiento privado**: la carga solo se habilita si el archivo de prueba **no** se puede descargar por URL.

Los comprobantes se respaldan junto con la base de datos. Si usas `MO_BOOKING_PRIVATE_DIR`, incluye esa carpeta en el respaldo.

## Tareas programadas

Los vencimientos y la cola de mensajes se procesan cada 5 minutos con WP-Cron, que depende de las visitas al sitio. Para mayor puntualidad, programa en el hosting cada 1 a 5 minutos:

```
wget -q -O - https://tu-sitio/wp-cron.php?doing_wp_cron >/dev/null 2>&1
```

o, con WP-CLI: `wp mo-booking process`.

Aunque la tarea se atrase, un horario vencido **no** se queda bloqueado: cada consulta y cada reserva revisan los vencimientos al momento.

## Datos de prueba

*Ajustes → Crear datos de prueba* carga el ejemplo de minis navideñas 2026: 150 espacios, la matriz de tarifas, las políticas y seis clientes ficticios. Todo queda marcado como **PRUEBA**, en una agenda separada que no ocupa tus horarios, y nunca envía mensajes. *Borrar datos de prueba* elimina solo esos datos.

## Desactivar y desinstalar

- **Desactivar** conserva todos los datos.
- **Eliminar** también los conserva, salvo que actives *Borrar todos los datos al eliminar* en Ajustes.

## Desarrollo

```bash
composer install
vendor/bin/phpunit --testsuite unit
WP_DIR=/ruta/wordpress bin/test-env.sh                  # WordPress de pruebas con base wp_test
MO_WP_DIR=/ruta/wordpress vendor/bin/phpunit            # unitarias + integración (incluye concurrencia)
bin/build-zip.sh                                        # genera dist/mo-booking-studio-X.Y.Z.zip
```

Documentación técnica:

- [`docs/decisiones.md`](docs/decisiones.md): decisiones técnicas.
- [`docs/api.md`](docs/api.md): contrato REST.
- [`docs/datos.md`](docs/datos.md): modelo de datos.
- [`docs/estado.md`](docs/estado.md): qué está implementado, simulado y pendiente, con el informe de pruebas.
- [`docs/manual.md`](docs/manual.md): manual de la administradora.
