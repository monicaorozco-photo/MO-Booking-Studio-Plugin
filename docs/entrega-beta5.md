# Entrega 0.2.0-beta.5 (rediseño)

Solo cambia la apariencia. **No cambia la base de datos** (sigue en el esquema 4), así que no pide confirmar respaldo al actualizar. Igual, haz un respaldo con UpdraftPlus antes de subirla.

## Cómo actualizar
1. Respaldo con UpdraftPlus (base de datos y plugins).
2. Plugins → Añadir nuevo → Subir plugin → `mo-booking-studio-0.2.0-beta.5.zip` → **Reemplazar**.
3. Si usas caché (del hosting, LiteSpeed, WP Rocket, etc.), vacíala para que cargue el diseño nuevo.

## Después de actualizar
1. **Colores de Elementor:** los colores que pusiste en la pestaña Estilo de los widgets "MO Booking Studio" y "MO Vista de reserva" siguen mandando sobre los nuevos. Para ver el diseño nuevo, bórralos (ícono de restablecer junto a cada color) y guarda.
2. **Ancho:** las dos columnas aparecen cuando el widget mide 760 px o más. Si tu sección es más angosta, ensánchala o usa el ancho completo.
3. **Tipografías:** el plugin usa Cormorant Garamond y Montserrat que ya carga tu sitio.
4. **Textos del desglose:** Reservas → Ajustes → "Desglose: …" (Precio, Descuento por fecha anticipada, Descuento cliente frecuente, Descuento por preventa, Precio con descuento).
5. **Revisa el anticipo de tus servicios:** había un error que lo dividía entre 100 si guardabas dos veces después de un aviso de error. Ya está corregido.

## Pruebas realizadas
- 213 pruebas automáticas en PHP 7.4 y 8.3.
- Navegador en computadora y celular: reserva completa hasta su página de reserva, carrera por el mismo horario, orden de campos y tabulador, tema que pinta todos los botones, panel y tabla de precios.
