# Respaldo, actualización y reversión

Versión actual: **0.2.0-beta.2** (esquema de datos **3**). Aplica al actualizar desde 0.2.0-beta.1 (esquema 2) o desde 0.1.x (esquema 1).

## Antes de actualizar

1. **Momento tranquilo.** Mientras no confirmes el respaldo, la reserva en línea queda en pausa con el mensaje "Estamos actualizando el sistema de reservas" (las reservas no se pierden; nadie puede reservar en ese rato).
2. **UpdraftPlus → Respaldar ahora**, con base de datos, plugins y uploads.
3. **Descarga** los archivos del respaldo y comprueba que ninguno pese 0 KB.
4. Si usas `MO_BOOKING_PRIVATE_DIR`, descarga también esa carpeta (los comprobantes viven ahí, fuera de uploads).
5. Guarda el ZIP de la versión que tienes instalada (para poder volver).
6. **Prueba primero en el subdominio HTTPS** con una copia del sitio (ver `docs/guia-google-cloud.md`, sección "Subdominio HTTPS").

## Actualizar

1. Plugins → Añadir nuevo → Subir plugin → `mo-booking-studio-0.2.0-beta.2.zip` → **Reemplazar la versión actual**.
2. Ve a **Reservas**: aparece "Actualización de datos pendiente". Hasta aquí no se modificó nada.
3. Marca "Tengo un respaldo descargado y verificado" → **Actualizar datos**.
4. Comprueba: reservas, clientes, pagos y comprobantes siguen ahí; la página de reservas funciona.
5. **Ajustes → Verificar almacenamiento privado** otra vez: esta versión exige verificar con la ruta exacta (la verificación anterior ya no cuenta).
6. **Contratos**: revisa la plantilla creada para cada servicio y asígnala si falta. **Un servicio sin contrato publicado no acepta reservas en línea.**
7. **Ajustes**: escribe el "Contacto público para Google Calendar" y revisa el "Texto junto al comprobante" y el número de WhatsApp.
8. En cada servicio: configura la entrega rápida (si la ofreces) y revisa las preguntas.

### Qué hace la migración (solo agrega; nada se borra, renombra ni recalcula)

| Paso | Efecto |
|---|---|
| Estructura | Columnas nuevas en reservas (niños, adultos, información importante, copia del contrato, archivada, invitación explícita, destino del anticipo al cancelar) y en clientes (archivado). Tablas nuevas de contratos y versiones. |
| `3_contracts_from_terms` | Las condiciones de cada servicio, más las condiciones generales de Ajustes al final, se convierten en la **versión 1** de una plantilla "Contrato – {servicio}" asignada a ese servicio. Los textos anteriores se conservan. Los servicios sin condiciones quedan sin contrato. |
| Reservas anteriores | Conservan precio, cotización, política, contacto, pagos, comprobantes, eventos de Google y cola tal cual. No se les inventa contrato, niños ni adultos: el panel muestra "reserva anterior, sin desglose" y "aceptó condiciones de una versión anterior". |

Se ensayó con datos de 0.2.0-beta.1: prueba automática `MigrationTest::test_upgrade_from_beta1_preserves_everything` y una actualización en navegador de un sitio local con reservas, pagos y eventos de Google simulados. En el sitio local, la única reserva que cambió después de actualizar fue una retención que **venció por tiempo** al reanudarse el sistema (comportamiento normal), no por la migración.

## Volver a 0.2.0-beta.1 sin perder datos

No se revierte el esquema: la beta.1 ignora las columnas y tablas nuevas, así que puede funcionar sobre la base ya migrada.

1. Plugins → sube el ZIP de 0.2.0-beta.1 → Reemplazar.
2. La beta.1 verá un esquema distinto al suyo y mostrará "Actualización de datos pendiente" (reserva en línea en pausa). Confirma el respaldo: la beta.1 solo **agrega** lo que le falte (no borra columnas ni tablas de la beta.2) y marca el esquema como 2.
3. Lo que se pierde mientras uses la beta.1 (los datos siguen en la base y vuelven al reinstalar la beta.2):
   - el formulario vuelve al de "Número de personas" y a las condiciones de texto;
   - las reservas siguen guardando sus columnas nuevas, pero la beta.1 no las muestra;
   - **vuelven los problemas de seguridad corregidos** (SEG-01 a SEG-06), incluido el borrado recursivo al desinstalar y la invitación de Google al confirmar sin anticipo. No uses la beta.1 con la preventa abierta.
4. Comprobantes marcados como "Sustituido" en la beta.2 se verán con el estado en texto (`replaced`) en la beta.1; no afecta la revisión.
5. Al volver a instalar la beta.2 pedirá de nuevo confirmar el respaldo (el esquema dice 2); al confirmar no se repite ningún paso de migración y los datos de la beta.2 siguen ahí.

Ensayado con los ZIP reales en PHP 7.4 (beta.2 → beta.1 → beta.2): una reserva con contrato, niños/adultos y entrega rápida se conservó intacta en ambos cambios.

## Volver a 0.1.x sin perder datos

Igual que arriba, más este paso antes de reinstalar, porque la 0.1.x no conoce el estado "En revisión":

```sql
UPDATE wp_mo_bookings SET status = 'payment_review' WHERE status = 'under_review';
```

(Usa el prefijo de tu sitio si no es `wp_`.)

## Volver exactamente al estado anterior

1. UpdraftPlus → **Restaurar** el respaldo previo (base de datos y plugins) y, si aplica, la carpeta de `MO_BOOKING_PRIVATE_DIR`.
2. Lo hecho después del respaldo se pierde (reservas, pagos y comprobantes nuevos incluidos).
3. En Google Calendar, borra a mano los eventos creados en ese tiempo: empiezan con "MO", "PRUEBA" o "Sesión con".

## Desinstalar

Desactivar o eliminar el plugin **no borra nada** salvo que en Ajustes actives "Borrar todos los datos al desinstalar" **y** escribas BORRAR en la confirmación, que queda ligada a la ruta exacta de la carpeta de comprobantes. Aun así, solo se borran los archivos de comprobantes registrados por el plugin, nunca otros archivos ni la carpeta completa.
