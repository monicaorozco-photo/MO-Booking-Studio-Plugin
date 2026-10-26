# Cambios

## 0.2.0-beta.4 — 26 de octubre de 2026 (lanzamiento de la preventa)

Sin cambios de base de datos (esquema 4). Guía: `docs/entrega-beta4.md`.

- Google Calendar: títulos por servicio con códigos (`{cliente}`, `{estado}`…); calendario aparte para las invitaciones a clientas (las existentes se mueven sin reenviarse) para ver un solo evento por cita.
- Vista de reserva: es también la pantalla final al reservar; contrato aceptado en lugar de las reglas de cancelación; comprobantes enviados (con vista segura de su propio archivo) y pagos recibidos; ubicación e indicaciones solo con la cita confirmada; aviso de comprobante dentro de su recuadro.
- Formulario: colores de días que el tema ya no puede tapar; texto editable para horarios ocupados; aviso editable antes del contrato y sin número de versión; casilla de avisos por WhatsApp y correo obligatoria (configurable); reglas de cancelación ocultas al público por omisión.
- Elementor: widget "MO Vista de reserva" con vista de ejemplo en el editor; controles de estilo para calendario, horarios, botones, tipografía y tarjetas.
- Notificaciones: correos con formato (editor con negritas, títulos, listas y enlaces) y copia en texto simple; texto propio para WhatsApp por aviso; los saltos de línea ya no se pierden al abrir WhatsApp desde el panel.
- Papelera: una reserva vigente se cancela sola sin avisar a la clienta.
- Cliente frecuente: el correo manda aunque el teléfono esté en otro expediente; la reserva se marca "Revisar teléfono".
- Página para imprimir: pagos recibidos; ubicación e indicaciones solo con la cita confirmada.
- Correcciones: borrar un cliente borra también sus contactos adicionales; prueba de privacidad del evento ya no depende del identificador al azar.

## 0.2.0-beta.3 — 23 de octubre de 2026 (beta para preventa controlada)

Esquema de datos 4. Detalle y pruebas en `docs/entrega-beta3.md`.

### Decisiones de la administradora que sustituyen reglas de la especificación de preventa
- **Eliminar sí está permitido** (solo con acceso al panel; los clientes no pueden): pagos registrados por error, clientes duplicados o de prueba aunque tengan reservas o contratos. Sustituye las reglas de "no borrar" de la especificación.
- **Precio frecuente automático por nombre y contacto** (como en la beta.1), sustituye la Salida B de la beta.2. Toda cita sigue pasando por la aprobación del anticipo.

### Clientes
- Correos y teléfonos adicionales por cliente; se usan para encontrarlo al reservar y para reconocer su tarifa frecuente.
- Posibles duplicados (mismo correo o teléfono, nombre igual sin acentos o con faltas leves), con "No son la misma persona".
- **Fusionar**: las reservas del duplicado pasan al principal con sus pagos, comprobantes y contratos; sus contactos quedan como adicionales; el duplicado va a la papelera. Sesiones previas: el mayor de los dos; completadas: se suman.

### Cliente frecuente (decisión B)
- "Ya me he tomado fotos con ustedes" pide nombre, correo y teléfono en campos separados.
- Coincide si el correo O el teléfono es del cliente y el nombre se parece. Sin descuento si correo y teléfono apuntan a clientes distintos o un contacto lo comparten varios.
- Muestra precios de cliente frecuente (y preventa). La reserva debe usar el contacto con el que se identificó. Límite de intentos por IP.
- Panel: insignia "Frecuente reconocido por nombre y contacto", filtro, y "Regresar a precio general" con un clic.

### Papelera y eliminación
- Papelera con restaurar para clientes, servicios, reservas y contratos; "Eliminar permanentemente" desde la papelera (en las listas pide escribir ELIMINAR).
- Eliminar un cliente con todo su historial (reservas, pagos, comprobantes con sus archivos, copias de contrato, enlaces, mensajes y eventos propios de Google) confirmando con su nombre. La bitácora conserva solo un registro sin datos personales.
- Eliminar un movimiento de dinero (sin papelera) con motivo obligatorio; nota en el historial con quién, cuándo, importe y motivo; saldo recalculado; aviso si una cita confirmada pierde el anticipo cubierto. "Revertir" sigue disponible.
- Una reserva vigente debe cancelarse antes de ir a la papelera.

### Comprobantes
- Si no se pueden subir archivos, el enlace privado y la pantalla final muestran el botón "Da clic en este botón para enviar tu comprobante por WhatsApp" al **número del negocio**, con el mensaje "Hola! Hago el envío de mi comprobante de anticipo a nombre de {nombre} con número de reserva {referencia}" (editables en Ajustes).
- Adjuntar desde el panel un comprobante recibido por WhatsApp o correo (mismas validaciones, carpeta privada, registro de quién lo subió).

### Contrato para la clienta
- Botón "Descargar mi contrato y datos de la sesión" en el enlace privado: página lista para imprimir o guardar como PDF con el contrato exacto (versión, fecha de aceptación y huella) y los datos de la sesión, sin notas internas. Solo funciona con la sesión del enlace privado.

### Listas estilo WordPress
- Clientes, reservas, servicios y contratos: pestañas con conteo, casillas, acciones masivas y por fila, filtros, búsqueda, orden y paginación.

### Notificaciones
- Plantillas propias de correo (con un evento o a mano desde la reserva) y de WhatsApp (se abren con el texto listo); activar, desactivar y quitar.
- Guía de códigos junto a cada texto (clic para insertar), aviso de códigos desconocidos o con una sola llave, vista previa con datos de ejemplo y "Restaurar el texto original" en los avisos del sistema.
- El registro guarda el texto exacto de cada mensaje ("Ver texto"), aunque la plantilla cambie o se quite.

### Herramienta aparte
- `mo-cargar-clientes-1.0.0.zip`: carga única de la base de clientes desde CSV, con revisión previa y deshacer (`docs/cargar-clientes.md`).

### Pruebas
- 200 pruebas PHPUnit (PHP 7.4 y 8.3); recorridos de navegador `preventa.js`, `admin-preventa.js`, `b3-clients.js` y `b3-admin.js`; actualización y reversión con los ZIP reales.

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
