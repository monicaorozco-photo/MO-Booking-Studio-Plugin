# Cambios

## 0.2.0-beta.6.5.2 — 30 de octubre de 2026

Corrección de la 6.5.1 (Google Calendar). Sin cambios de base de datos.

- Los eventos que ya se habían movido al calendario nuevo quedaron marcados como "Se quedan en el calendario anterior" y fallaban con 403: al intentar moverlos de nuevo, Google responde "cannotChangeOrganizer" (no "no existe"). Ahora, ante esa respuesta (o 403), primero se revisa si el evento ya está en el calendario nuevo y, si está, se adopta ahí sin avisos.
- Al actualizar se revisan otra vez las reservas marcadas así y se cierran sus trabajos fallidos. Solo queda en el calendario anterior lo que de verdad sigue ahí y Google no deja mover.

## 0.2.0-beta.6.5.1 — 30 de octubre de 2026

Corrección de Google Calendar. Sin cambios de base de datos.

- Al cambiar el calendario de invitaciones (o el destino), los eventos sí se movían pero el plugin los marcaba como fallidos ("No se pudo verificar el evento movido"): Google borra las propiedades privadas con las que el plugin reconoce sus eventos al moverlos de calendario. Ahora se las vuelve a poner después de moverlos, sin avisar a la clienta.
- Al actualizar, los eventos que ya se habían movido se encuentran en el calendario nuevo y se dan por migrados, sin reenviar invitaciones ni crear duplicados; los trabajos fallidos de esas reservas se cierran solos al sincronizar bien.
- Si Google no permite mover una invitación ("cannotChangeOrganizer"), se queda en el calendario anterior y sigue funcionando; ya no aparece como error y el panel la lista aparte.

## 0.2.0-beta.6.5 — 30 de octubre de 2026

Sin cambios de base de datos.

- **Pagos, descuentos y cargos extra** (antes "Comprobantes y pagos"), en cada reserva:
  - Descuentos en $ o % y cargos extra en $, varios por reserva, con el nombre que ve la clienta y una nota interna; se pueden quitar. Cambian el total y el saldo, no el anticipo (salvo que el total quede por debajo). Un descuento no puede dejar el total por debajo de lo ya pagado. Sin avisos: la clienta los ve en su resumen de inversión. Se conservan al editar, reprogramar o recalcular.
  - La cortesía se movió a este recuadro.
- **Comprobantes del saldo y de abonos:** con la cita confirmada (o realizada) y saldo pendiente, la clienta puede subir más comprobantes desde su página; la cita sigue confirmada y el comprobante te llega "Por revisar". Al quedar liquidada deja de recibirlos. El máximo de archivos por reserva pasa de 5 a 10 (si no lo habías cambiado).
- **Registro de mensajes de cada reserva:** pestañas "Enviados" y "Programados", orden del más reciente o del más antiguo y 5 mensajes por página.
- **Página de reserva en este orden:** reserva y datos de la clienta (nombre, correo y WhatsApp) → anticipo pendiente (o saldo pendiente con la cita confirmada; desaparece al liquidar) → datos bancarios → enviar comprobante → resumen de inversión y contrato → cambiar fecha, pedir cancelación o corregir mis datos (por WhatsApp) → ¿Necesitas ayuda? (WhatsApp). Sin "Cerrar sesión".
- **Información importante:** la pregunta y el texto de ejemplo del formulario se editan en *Ajustes*.
- **Guía de vestimenta por servicio:** enlace de Medios (JPG o PDF) y texto del botón en el servicio. Si lo llenas, aparece el botón en el correo de Cita confirmada y en la página de reserva con la cita confirmada.
- **Reactivar reservas vencidas** desde el panel: si el horario sigue libre (agenda y Google), muestra el precio actualizado a las tarifas de hoy y la deja en "Pendiente de pago" con un nuevo plazo (o confirmada si lo pagado ya cubre el anticipo). Casilla "Avisar a la clienta". Si el horario ya está ocupado, lo indica y no reactiva.

## 0.2.0-beta.6.4 — 29 de octubre de 2026

Sin cambios de base de datos. Al actualizar se aplican las plantillas nuevas de correo (una sola vez).

- Correos ordenados: el texto del editor de WordPress se envía con sus párrafos (antes salía todo amontonado). Aplica también a las plantillas ya guardadas.
- Plantillas nuevas de los 10 correos para la clienta (documento "Plantillas de correo electrónico"): servicio, fecha, hora, ubicación y número de reserva van solo en la tarjeta; el enlace, en el botón; la firma, en el pie. El título grande es el asunto y cada tarjeta lleva su encabezado ("📅 Detalles de la solicitud", "📋 Datos de referencia"…).
  - WhatsApp no cambia: en los avisos que usaban el texto del correo, ese texto se copió tal cual al campo "Texto para WhatsApp".
  - Nuevo código `{{detalles}}`: la tarjeta aparece donde lo escribas (sin él, al final del mensaje). En WhatsApp son renglones de texto.
  - Nuevo campo por aviso: "Encabezado de la tarjeta".
- Envío de prueba: en *Notificaciones → Envío de prueba* eliges un aviso (o plantilla propia de correo) y te llega con el diseño completo, datos de ejemplo y "[PRUEBA]" en el asunto, aunque los correos estén en simulación. Queda en el registro con la etiqueta "Prueba".
- Botón "Consultar mi reserva": se quita solo cuando el enlace privado de esa reserva ya venció o se anuló.
- Logo de los correos: máximo 150 × 150 px (conserva su proporción).
- Pie de los correos: "Mónica Orozco | Fotógrafa 📸" y "Este es un mensaje automático del sistema de reservas de MO Fotógrafa." (si ya lo habías cambiado, se respeta el tuyo).

## 0.2.0-beta.6.3 — 28 de octubre de 2026

Sin cambios de base de datos. Las reservas ya hechas conservan su precio.

- Correos con la identidad visual de la marca: franja crema con el logo (o el nombre en Cormorant Garamond) y "Fotógrafa", título grande en marrón #63422B, el texto del aviso, tarjeta crema con fecha, hora, servicio, ubicación (solo con la cita confirmada) y número de reserva, botón marrón "Consultar mi reserva" al enlace privado y pie con firma y "Este es un mensaje automático…". Una columna de 600 px.
  - En *Ajustes*: logo, texto bajo el logo, texto del botón y pie de los correos.
  - En cada aviso de *Notificaciones*: título del correo (con emojis), casilla de la tarjeta de detalles y "Ver el diseño del correo" con datos de ejemplo.
- WhatsApp manual: el menú de la reserva ofrece todos los avisos para la clienta (también Retención vencida, Pago aprobado, Comprobante recibido y Solicitud no aprobada) y preselecciona el que corresponde al estado de la reserva. "Pago aprobado" y "Corrección solicitada" llevan el importe y el motivo del último aviso.
- Asistentes y mascotas:
  - Con "Las mascotas cuentan como asistentes", el formulario y el resumen las suman al número de asistentes.
  - Servicio: "Asistentes incluidos", "Máximo de adultos" y "Costo por asistente adicional". El máximo ahora es solo de adultos (es por el espacio); niños y mascotas no lo llenan, pero sí se cobran como asistentes adicionales.
  - Nuevo "Aviso para grupos grandes" por servicio, debajo del número de asistentes (vacío = no se muestra). Texto inicial: consultar cada caso porque algunos grupos requieren un espacio doble de sesión.
  - En el desglose: "N asistentes adicionales".

## 0.2.0-beta.6.2 — 28 de octubre de 2026

- Datos para transferencia: el renglón "Banco" se muestra sin botón de copiar y los datos se ven en letra más pequeña.

## 0.2.0-beta.6.1 — 28 de octubre de 2026

- Avisos más cortos: `{{hora}}` dice "10:30 hrs" y `{{vencimiento}}` termina en "hrs", sin zona horaria. Las plantillas originales ya no llevan `({{zona}})`; en tus plantillas propias bórralo si no lo quieres.
- Datos para transferencia: un botón "Copiar" por renglón ("Etiqueta: valor"); copia solo el valor y los números largos (CLABE, tarjeta) sin espacios ni guiones.
- Bienvenida: nuevo aviso debajo de las opciones, editable en el widget de Elementor (vacío = no se muestra), en lugar de "El proceso es el mismo en ambos casos…".

## 0.2.0-beta.6 — 28 de octubre de 2026

Sin cambios de base de datos (esquema 4). Guía: `docs/entrega-beta6.md`.

- Corrección: los avisos (y los ajustes) volvían a los textos originales al guardar si llevaban emojis y la tabla de opciones del hosting es utf8 sin "mb4". Ahora los emojis se guardan de forma compatible con cualquier base de datos y, si algo no se guarda, el panel muestra un error.
- Avisos: `{{nombre}}` es el primer nombre ("Hola María"); `{{nombre_completo}}` da el nombre entero. En tus avisos internos sigue llegando el nombre completo.
- Cortesía (solo en el panel): confirma la cita con total y anticipo en $0, sin avisos ni invitación de Google a la clienta; etiqueta "Cortesía" en la lista y en la reserva; no se permite si ya hay pagos; se puede deshacer.
- Registro de mensajes paginado (25 por página).
- Cambio de fecha por la clienta desde "Mi reserva" (apagado por omisión, en Ajustes): ventana con la política editable, una sola vez, con anticipación mínima (36 h por omisión), recalcula el precio conservando su tipo de cliente y su preventa, pide aceptar la diferencia si cuesta más, aplica la regla de cambio de su reserva, actualiza Google Calendar y te avisa. Después queda un aviso fijo editable.
- Elementor y shortcodes: mostrar u ocultar la marca y el nombre del servicio, por separado en el formulario y en "Mi reserva" (`show_brand="no"`, `show_title="no"`).

## 0.2.0-beta.5.1 — 28 de octubre de 2026

- WhatsApp: los emojis ya no se pierden. Los enlaces usan api.whatsapp.com en lugar de wa.me (que en computadora los convertía en "�"), y si la base de datos del hosting no admite emojis se guardan de forma segura y se recuperan al enviar.
- Ajustes → "Abrir mis mensajes en": la aplicación de WhatsApp o WhatsApp Web (para enviar desde tu cuenta de WhatsApp Business en la computadora).

## 0.2.0-beta.5 — 27 de octubre de 2026 (rediseño)

Solo apariencia; sin cambios de base de datos (esquema 4). Guía: `docs/entrega-beta5.md`.

- Rediseño del formulario y de la vista de reserva con la paleta Off White, Beige, Taupe, Café, Espresso y Negro, Cormorant Garamond en títulos y Montserrat en textos, tarjetas con íconos y dos columnas en computadora.
- Tus datos: primero contacto y después asistentes (con botones − / +); extras y contrato en la misma página.
- Desglose del precio: precio de referencia tachado, descuento por fecha, cliente frecuente y preventa, y precio con descuento (en el resumen, la vista de reserva, la página para imprimir y la tarifa estimada). Etiquetas editables en Ajustes. El total no cambia.
- Vista de reserva: tarjeta principal con lo que falta pagar, avance de la reserva, botones para copiar datos bancarios y referencia, comprobante con nombre del archivo elegido; en celular, si falta pagar, eso va primero.
- Página para imprimir con la misma paleta.
- Elementor: disposición en dos columnas por omisión; nuevo color "Fondo suave".
- Corrección: tras un error al guardar un servicio, el anticipo, la entrega rápida y otros importes se mostraban divididos entre 100 y se guardaban así al volver a guardar.

## 0.2.0-beta.4.2 — 27 de octubre de 2026

- Elementor: el widget "MO Vista de reserva" ya muestra la reserva de ejemplo dentro del editor (se quedaba en "Cargando tu reserva…").

## 0.2.0-beta.4.1 — 27 de octubre de 2026

Solo estilos (sin cambios de datos).
- Blindaje contra los estilos de botones del tema y de Elementor: días, horarios, botones y opciones del formulario y de la vista de reserva conservan sus colores aunque el tema use `!important` (probado con un tema que pinta todos los botones de negro: `tests/e2e/tema-agresivo.js`).
- El horario seleccionado conserva su color al pasar el cursor.
- Los colores de botones del widget de Elementor ahora se aplican como variables (siguen funcionando con el blindaje).

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
