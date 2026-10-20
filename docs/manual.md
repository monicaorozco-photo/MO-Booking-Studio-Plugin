# Manual de la administradora

## El día a día

**Reservas → Inicio** muestra lo que requiere tu atención:

- comprobantes por revisar (los más antiguos primero);
- solicitudes que esperan pago o aprobación, con su hora de vencimiento;
- citas de los próximos 14 días y sus saldos;
- dinero cobrado en el mes, siempre separado del valor de las citas reservadas.

### Cuando llega un comprobante

1. Abre la reserva y pulsa **Ver archivo**.
2. **Revisa en tu banca que el dinero llegó.** El comprobante solo es una evidencia; no confirma el depósito.
3. Escribe el importe que realmente recibiste, la fecha y una referencia, y pulsa **Aprobar pago**.
   - Si cubre el anticipo y está marcada la casilla "Confirmar la cita", la reserva queda **Confirmada** y la persona recibe un solo aviso.
   - Si recibiste menos, el pago se guarda y la reserva pasa a **Corrección solicitada**, indicando cuánto falta.
4. Si el archivo no sirve, usa **Pedir corrección** y escribe el motivo, que verá la persona. El horario sigue apartado durante el plazo de corrección.

Aprobar dos veces el mismo comprobante no duplica el pago: el sistema lo impide.

### Pagos sin comprobante

Para el saldo en efectivo el día de la sesión, usa *Registrar pago sin comprobante*.

### Reservas que recibes por WhatsApp o en persona

En **Reservas → Nueva reserva**:

1. Elige el servicio, la fecha y el horario.
2. Si la persona apartó durante una preventa, indica cuándo te lo pidió para respetar esa tarifa.
3. Si ya te pagó, escribe el anticipo recibido.

### Cliente frecuente

Al abrir el enlace de reservas, la persona elige **Soy cliente nuevo** o **Ya me he tomado fotos con ustedes**. En el segundo caso escribe su nombre y su correo o teléfono:

- Si su expediente tiene las sesiones necesarias (umbral en Ajustes, 3 por defecto) o lo marcaste como frecuente, ve los precios de cliente frecuente y la reserva queda como "Cliente frecuente (verificado en línea)".
- Si no se confirma, ve los precios generales y la reserva muestra **Frecuente por revisar**.

Por eso conviene cargar a tus clientas con sus sesiones previas en **Clientes** antes de abrir reservas. Si confirmas a mano a alguien que quedó por revisar:

1. Pulsa *Aplicar tarifa de cliente frecuente…*.
2. Revisa la diferencia de precio y escribe el motivo.

El precio se recalcula con la fecha de solicitud original. Si ya había pagado de más, aparece el excedente para que registres una devolución o un saldo a favor.

Para que el sistema reconozca a la persona en el futuro, ve a **Clientes**, abre su expediente y anota sus sesiones previas o márcala como frecuente.

### Cambios y cancelaciones

Desde la reserva:

- **Reprogramar**: elige la nueva fecha y el horario. Antes de confirmar verás qué pasa con el anticipo según la política que la persona aceptó. Si la nueva fecha exige otro anticipo, la reserva vuelve a "Pendiente de pago".
- **Cancelar**: elige *a petición del cliente* (se aplica su política) o *por parte del negocio* (sin penalización; todo lo pagado queda como devolución pendiente).
- **Devoluciones**: el sistema nunca transfiere dinero. Cuando hagas la transferencia, regístrala como *Devolución realizada*.
- Después de la sesión, márcala como **completada**; cuenta una sesión para el cliente. También puedes marcar una **inasistencia**.

### Mensajes

- Los correos salen solos en cada paso, pero solo si en Ajustes está activado el **Envío real**.
- Para WhatsApp, usa **Abrir WhatsApp** en la reserva. Después de enviar el mensaje, márcalo como enviado.
- Si la persona perdió su enlace privado, cópialo desde la reserva. Si crees que alguien más lo tiene, pulsa *Generar enlace nuevo*: el anterior deja de funcionar.

## Configurar una temporada (ejemplo: minis navideñas)

1. **Servicios → Agregar servicio**:
   - duración 20 min, margen después 10 min, intervalo 30 min;
   - 6 personas incluidas, máximo 10, $100 por persona adicional; mascotas que no cuentan como personas;
   - anticipo fijo de $500;
   - aviso para el 19 y 20 de diciembre;
   - estado **Publicado**.
2. **Disponibilidad → Cargar varias fechas**: una línea por día, por ejemplo `2026-12-12 10:00-13:00, 15:00-19:00`. Revisa en la *Vista previa de espacios* que el total sea el esperado.
3. **Precios por temporada** (en el mismo servicio): pon la fecha límite de preventa (31/10/2026 23:59) y una fila por rango de fechas con los cuatro precios. Ejemplo: 15 nov – 4 dic: preventa $1,400 / $1,200 (frecuente), regular $1,600 / $1,400, marcada como **Especial**. Al pasar la fecha límite, el mismo enlace cobra los precios regulares.
4. **Políticas** del servicio:
   - **Reprogramación**: 0–24 h, anticipo retenido, nuevo anticipo $500; 24 h o más, anticipo a su favor.
   - **Cancelación**: 0 h o más, anticipo retenido.
5. Pon el widget de Elementor o el shortcode en tu página de reservas y haz una prueba completa desde tu celular.

## Si algo sale mal

| Situación | Qué hacer |
|---|---|
| Un comprobante llegó después del vencimiento | Aparece como **Tardío: conciliar**. El horario no se recupera automáticamente: confirma el depósito y decide con la persona (otra fecha o devolución). |
| Un mensaje falló | En *Notificaciones → Fallidos*, pulsa *Reintentar*. |
| Las tareas parecen detenidas | Pulsa *Procesar pendientes ahora* en Inicio. Los horarios vencidos se liberan solos en cada consulta. |
| Cambiaste un horario y quedó una cita fuera | La *Vista previa* de Disponibilidad la señala; resuélvela reprogramando. |
| Un pago se registró por error | En la reserva, *Revertir un movimiento*. Nada se borra; queda en el historial. |
