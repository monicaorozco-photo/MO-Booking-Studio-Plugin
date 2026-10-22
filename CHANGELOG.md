# Cambios

## 0.2.0-beta.2 — 22 de octubre de 2026 (beta para preventa controlada)

Alcance: P0 y P0 condicional de `Especificacion_Maestra_MO_Booking_Studio_Preventa.md`, con la **Salida B** para clientes frecuentes y CSV. Esquema de datos 3.

### Seguridad (SEG-01 a SEG-06)
- **SEG-01 Carpeta privada**: se valida la ruta canónica; se rechazan la raíz, el directorio del usuario, la raíz de WordPress, `wp-content`, uploads completo, el document root y la carpeta temporal. Carpeta exclusiva con marcador de propiedad; una carpeta ajena con archivos se rechaza. Fuera del sitio = privada; dentro de uploads se prueba la URL exacta **con TLS verificado** y solo 401/403/404/410 sin contenido cuenta como privada; cualquier otro resultado deshabilita la carga. La verificación queda ligada a la ruta.
- **SEG-02 Desinstalación**: ya no borra carpetas de forma recursiva. Solo borra los archivos de comprobantes registrados, exige el marcador y una confirmación separada ("BORRAR") ligada a la ruta exacta, que se muestra antes de activar la opción.
- **SEG-03 Invitación**: la invitación de Google depende de anticipo aprobado, anticipo cero o la acción explícita "Invitar al cliente sin anticipo" (motivo y auditoría). Confirmar por excepción ya no invita.
- **SEG-04 Precio frecuente (Salida B)**: deshabilitado el precio frecuente automático; "Ya me he tomado fotos con ustedes" es una solicitud de revisión con precio general; ningún token cambia el precio en el servidor. La tarifa frecuente se aplica con la acción auditada del panel.
- **SEG-05 Cambio de calendario destino**: los eventos propios se **mueven** al calendario nuevo (sin copia ni reinvitación), se verifican y la pantalla muestra el progreso; los fallos se reintentan.
- **SEG-06 Requisitos**: al activar se exigen PHP 7.4+, `mbstring` y `sodium`; si falta algo no se activa a medias.
- Si falla el registro de un comprobante después de guardarlo, el archivo se retira (sin huérfanos).
- Al reconectar Google después de desconectarlo a mano, las reservas vigentes se vuelven a encolar.

### Formulario público (6.3, 6.4)
- Orden exacto: Fecha de sesión (con "Cambiar", que conserva lo escrito) → Número de asistentes (niños + adultos, sin 6 predeterminado) → Niños → Adultos → Mascotas (solo si es Pet friendly; sin la leyenda "no cuentan") → Información importante (2,000 caracteres) → Nombre y apellido → Correo → Teléfono (WhatsApp) → preguntas → **Entrega rápida** al final.
- Validación de enteros ≥ 0 en navegador y servidor; la API pública ignora un "número de personas" suelto.
- Entrega rápida como extra con precio por servicio; nombre y precio congelados en la reserva, incluso al reprogramar o editar.
- Preguntas ordenables; la reserva guarda una instantánea (etiqueta, tipo, opciones y respuesta).
- Nombre sin espacios repetidos; correo mostrado como se escribió y normalizado para búsquedas.

### Contratos (6.5)
- "Políticas" pasa a llamarse **Contratos**: plantillas con editor de WordPress, borrador, vista previa y versiones publicadas inmutables con hash SHA-256. Las reglas de cancelación quedan como pestaña.
- Cada servicio elige una plantilla; acordeón debajo del formulario y casilla obligatoria. La reserva congela título, texto, versión, hash y fecha de aceptación. Si la versión cambió mientras reservaba, se pide aceptarla de nuevo. Sin contrato publicado no hay reserva en línea.
- No se guarda la IP de aceptación.

### Panel
- Reservas: columnas de anticipo, total y saldo, Calendar y conflicto; filtros de pago, conflicto y archivadas; **editar** datos, asistentes, extras e información importante con vista previa y auditoría de antes/después; **archivar/restaurar**; **eliminar** solo reservas de prueba sin dependencias (muestra las dependencias).
- Cancelación con motivo y **destino del anticipo** (según política, se pierde, se acredita o por resolver).
- Corrección con motivo de la lista u "Otro" escrito; una corrección adicional solo como excepción auditada. El comprobante anterior queda como "Sustituido".
- Servicios: duplicar, activar/desactivar, archivar/restaurar y eliminar solo si nunca se usó.
- Clientes: archivar/restaurar y eliminar solo sin reservas.
- Detalle de reserva: niños/adultos, información importante y copia exacta del contrato con verificación del hash.

### Google Calendar (9.4, 9.5)
- Evento interno: estado (pendiente, en revisión, confirmada), contacto, correo, niños/adultos, mascotas, extras, pagos, información importante y enlace al panel.
- Evento del cliente: solo nombre del estudio, servicio, duración, ubicación, indicaciones y **contacto público** (nuevo ajuste). Ya no incluye la referencia ni el texto de entrega.

### WhatsApp y textos
- "Abrir WhatsApp" del panel en pestaña nueva (`noopener noreferrer`).
- Texto editable junto a la carga del comprobante con `{whatsapp}`; botón del portal para pedir cambio o cancelación por WhatsApp.
- Estado de mensajes "Preparado (en cola)".

### Migración
- `3_contracts_from_terms`: condiciones de cada servicio + generales → contrato v1 por servicio. Requiere confirmar respaldo (reserva en línea en pausa mientras tanto).

### Pruebas
- 175 pruebas PHPUnit (PHP 7.4 y 8.3) y recorridos de navegador `tests/e2e/preventa.js` y `admin-preventa.js`.

## 0.2.0-beta.1 — 21 de octubre de 2026
Google Calendar (OAuth, ocupación, eventos interno y del cliente, cola, empalmes), reglas de pago y comprobante v0.2, migración con respaldo previo.

## 0.1.1 — 20 de octubre de 2026
Pantalla de bienvenida, precios por temporada con preventa automática, calendario con íconos de precio especial.
