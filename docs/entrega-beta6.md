# Entrega 0.2.0-beta.6

**No cambia la base de datos** (sigue en el esquema 4). Igual, haz un respaldo con UpdraftPlus antes de subirla.

## Cómo actualizar
1. Respaldo con UpdraftPlus.
2. Plugins → Añadir nuevo → Subir plugin → `mo-booking-studio-0.2.0-beta.6.zip` → **Reemplazar**.
3. Vacía la caché de WP Fastest Cache (Delete Cache).

## Después de actualizar

### 1. Avisos que volvían a los textos originales
Tu base de datos no acepta emojis tal cual y WordPress rechazaba el guardado sin avisar. Ya quedó corregido:
- Entra a **Reservas → Notificaciones**, vuelve a escribir tus cambios (con emojis si quieres) y guarda.
- Revisa también **Reservas → Ajustes**: si alguna vez guardaste un texto con emoji, pudo no haberse guardado.

### 2. Saludo solo con el nombre
`{{nombre}}` ahora es el primer nombre ("Hola María"). No tienes que cambiar tus plantillas. Si en algún lugar quieres el nombre completo, usa `{{nombre_completo}}`.

### 3. Cortesía
En cada reserva: **Marcar como cortesía** (con una nota opcional). Queda confirmada con total de $0, sin avisar a la clienta y sin invitación de Google. Si después quieres mandarle la invitación, usa "Invitar al cliente sin anticipo". Para deshacer: **Quitar cortesía**.

### 4. Cambio de fecha por la clienta
Viene **apagado**. Para activarlo: **Reservas → Ajustes → Cambio de fecha por la clienta**:
- **Activar.**
- **Anticipación mínima:** 36 horas.
- **Texto de la ventana:** tu política (los renglones con "- " salen como lista).
- **Aviso cuando ya usó su cambio.**

Pruébalo primero con una reserva tuya. A ti te llega el aviso "La clienta cambió su fecha" y a ella la confirmación "Tu cita cambió de fecha". Tú, desde el panel, puedes re-agendar siempre como excepción (no cuenta como su cambio).

### 5. Ocultar marca o nombre del servicio
En Elementor, en cada widget ("MO Booking Studio" y "MO Vista de reserva"): **Mostrar nombre de la marca** y **Mostrar nombre del servicio**. Son independientes entre los dos widgets.

## Pruebas realizadas
- 221 pruebas automáticas en PHP 7.4 y 8.3 (nuevas en `tests/Integration/Beta6Test.php`).
- Navegador, con una base de datos utf8 como la de tu hosting: avisos y ajustes con emojis, registro paginado, cortesía, cambio de fecha completo en computadora y aviso posterior en celular, marca y título ocultos, y el recorrido de preventa completo.
