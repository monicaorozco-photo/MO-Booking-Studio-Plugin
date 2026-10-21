# Respaldo, actualización y reversión (0.1.1 → 0.2)

## Antes de actualizar

1. **Elige un momento tranquilo** (sin clientas reservando). Mientras confirmas el respaldo, la reserva en línea queda en pausa con el mensaje "Estamos actualizando el sistema de reservas".
2. **UpdraftPlus → Respaldar ahora**, con base de datos, plugins y uploads marcados.
3. **Descarga** los archivos del respaldo a tu computadora. Comprueba que ninguno pese 0 KB.
4. Si definiste `MO_BOOKING_PRIVATE_DIR` (carpeta propia de comprobantes), descarga también esa carpeta.
5. Guarda una copia del ZIP de la versión 0.1.1 del plugin.

## Actualizar

1. Plugins → Añadir nuevo → Subir plugin → ZIP de la 0.2 → **Reemplazar la versión actual**.
2. Ve a **Reservas**. Aparece "Actualización de datos pendiente". Nada se ha modificado todavía.
3. Marca "Tengo un respaldo descargado y verificado" → **Actualizar datos**.
4. Comprueba: tus reservas, clientes y pagos siguen ahí; la página de reservas vuelve a funcionar.

Qué cambia en los datos (solo agrega; nada se borra ni se renombra):
- Reservas "Pendiente de aprobación" → "Confirmada", sin enviar avisos (queda en el historial).
- Cada reserva recibe su hora límite de pago (creación + horas de retención).
- Los comprobantes se numeran por versión.
- Corrección de comprobante: de 12 a 2 horas.
- Se fija la clave de los enlaces privados, para que agregar `MO_BOOKING_SECRET` después no cambie los enlaces ya enviados.
- Tablas nuevas para Google: cola de sincronización, eventos vinculados y empalmes.

## Volver a 0.1.1 conservando los datos nuevos

La 0.1.1 ignora las tablas y columnas nuevas. La única excepción es el estado "En revisión", que la 0.1.1 no conoce.

1. Desconecta Google en Reservas → Google Calendar (opcional; los eventos ya creados quedan en tu calendario).
2. En phpMyAdmin (o la herramienta SQL del hosting), con el prefijo de tu sitio (normalmente `wp_`):
   ```sql
   UPDATE wp_mo_bookings SET status = 'payment_review' WHERE status = 'under_review';
   ```
3. Plugins → sube el ZIP de la 0.1.1 → Reemplazar.
4. Revisa que las reservas abran en el panel.

Notas:
- Lo que hayas aprobado en 0.2 sigue como "Confirmada" en 0.1.1 (es un estado que ya existía).
- Si después vuelves a instalar la 0.2, no pide respaldo otra vez y no repite pasos.

## Volver a 0.1.1 exactamente como estaba

1. UpdraftPlus → Restaurar el respaldo del paso "Antes de actualizar" (base de datos y plugins).
2. Lo hecho después del respaldo se pierde (reservas nuevas incluidas).
3. En Google Calendar, borra a mano los eventos creados en ese tiempo: todos empiezan con "MO" o "Sesión con".
