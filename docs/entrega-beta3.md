# Entrega 0.2.0-beta.3

**Sigue siendo una beta** para la preventa controlada. Antes de abrir la preventa a todos falta la prueba en un sitio con HTTPS (subdominio o el sitio real) con Google, correo SMTP y tareas programadas reales.

## Cómo actualizar

1. Respaldo completo (UpdraftPlus: base de datos, plugins y uploads) y descárgalo.
2. Plugins → Añadir nuevo → Subir plugin → `mo-booking-studio-0.2.0-beta.3.zip` → **Reemplazar**.
3. Reservas → confirma el respaldo → **Actualizar datos**.
4. Revisa en Ajustes el **Número de WhatsApp del negocio** (tu número) y el mensaje del comprobante.

Para volver a la beta.2: `docs/respaldo-y-reversion.md`.

## Qué cambió y dónde encontrarlo

| Necesidad | Dónde |
|---|---|
| Clientes duplicados | Clientes → pestaña **Posibles duplicados** → **Fusionar…** (o "No son la misma persona"). También desde la ficha del cliente. |
| Otro correo o teléfono del mismo cliente | Ficha del cliente → **Otros correos y teléfonos**. |
| Precio frecuente automático | "Ya me he tomado fotos con ustedes" pide nombre, correo y teléfono. En Reservas aparece la insignia **Frecuente reconocido por nombre y contacto**; en la reserva, **Regresar a precio general**. |
| Pago registrado por error | Reserva → tabla de **Movimientos** → **Eliminar…** (motivo obligatorio). |
| Borrar un cliente con reservas | Ficha del cliente → **Eliminar definitivamente** (escribe su nombre). O envíalo a la papelera y elimínalo desde ahí. |
| Recuperar algo borrado | Pestaña **Papelera** de Clientes, Reservas, Servicios o Contratos → **Restaurar**. Los movimientos de dinero no pasan por la papelera. |
| Comprobante recibido por WhatsApp | Reserva → **Adjuntar un comprobante que recibiste por WhatsApp o correo**. |
| Comprobante cuando no se pueden subir archivos | Automático: la clienta ve el botón a tu WhatsApp con el mensaje listo. Textos en Ajustes. |
| Contrato en PDF para la clienta | Su enlace privado → **Descargar mi contrato y datos de la sesión** → "Guardar como PDF". |
| Plantillas de mensajes | Notificaciones → **Nueva plantilla**. Guía de códigos al lado del texto, vista previa y aviso si un código no existe. |

## Reglas que conviene conocer

- **Cliente frecuente**: coincide el correo **o** el teléfono (principal o adicional) y el nombre se parece (sin acentos, faltas leves). No aplica si el correo es de un cliente y el teléfono de otro, ni si un contacto lo comparten varios clientes. La reserva debe usar el mismo correo o teléfono con el que se identificó. El umbral sigue en Ajustes (3 sesiones).
- **Papelera**: una reserva vigente se cancela antes de enviarla a la papelera. Un cliente con reservas vigentes no va a la papelera (cancélalas, fusiónalo o elimínalo definitivamente). Lo que está en la papelera no se usa para reconocer clientes ni se ofrece al público.
- **Eliminar definitivamente** un cliente o una reserva borra también pagos, comprobantes (archivos), copia del contrato, enlace privado, mensajes y sus eventos en Google. La bitácora guarda solo cuántos elementos se borraron, sin datos personales. Si Google no responde, no se borra nada y puedes reintentar.
- **Eliminar un movimiento** recalcula el saldo, pero no cambia el estado de la cita: si una cita confirmada se queda sin anticipo, verás un aviso para decidir.
- **Fusionar**: el principal conserva su nombre, correo y teléfono; sesiones previas = el mayor de los dos; sesiones completadas = la suma.
- **Mensajes**: el registro guarda el texto exacto que se envió. Los códigos van con doble llave: `{{nombre}}`. (Solo el mensaje de WhatsApp del comprobante, en Ajustes, usa una llave: `{nombre}` y `{referencia}`.)
- **Listas**: "Bulk actions"/"Apply" aparecen en español si WordPress está en español.

## Pruebas realizadas

- 200 pruebas automáticas en PHP 7.4 y 8.3 (15 nuevas de la beta.3 en `tests/Integration/Beta3Test.php`, más las de cliente frecuente reescritas).
- Navegador: reserva completa en teléfono con identificación de frecuente (`preventa.js`), panel (`admin-preventa.js`), clientes, duplicados, fusión y papelera (`b3-clients.js`), movimientos, comprobante desde el panel, plantillas, enlace privado, página imprimible y botón de WhatsApp (`b3-admin.js`).
- ZIP real: beta.2 → beta.3 → beta.2 → beta.3 en PHP 7.4, sin pérdida de datos.

## Pendiente

- Prueba en HTTPS con Google, SMTP y cron reales.
- beta.4: cancelar o cambiar de fecha desde el enlace privado de la clienta.
