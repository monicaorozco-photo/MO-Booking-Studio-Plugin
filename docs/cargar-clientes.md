# Cargar tu base de clientes (una sola vez)

Herramienta aparte: `mo-cargar-clientes-1.0.0.zip`. Se instala, se usa una vez y se elimina. Necesita MO Booking Studio 0.2.0-beta.2 o posterior.

**Tus datos no salen de tu sitio.** El archivo se lee en tu propio WordPress y se borra justo después de leerlo; no se guarda en Medios ni se envía a ningún servicio. La revisión se guarda 30 minutos solo para la persona que la hizo y se borra al cargar o cancelar.

## 1. Prepara el archivo

Columnas reconocidas (el orden no importa; mayúsculas y acentos tampoco):

| Columna | Nombres aceptados | Obligatoria |
|---|---|---|
| Nombre | Nombre, Nombre completo, Cliente | Sí |
| Apellido | Apellido, Apellidos | No (se une al nombre) |
| Sesiones | No. Sesiones, Sesiones | No (vacío = 0) |
| Correo | Email, Correo | Correo **o** teléfono |
| Teléfono | Teléfono, Celular, WhatsApp | Correo **o** teléfono |
| País | País (MX o US) | No |
| Notas | Notas | No |

- **Teléfonos que salen como `#ERROR!`**: Google Sheets toma un número que empieza con `+` como fórmula. Selecciona la columna Teléfono → Formato → Número → **Texto sin formato** y vuelve a escribirlos, o escríbelos sin el `+` (por ejemplo `52 656 123 4567` o `656 123 4567`). Si se quedan así, el cliente se carga solo con su correo y la revisión te lo avisa.
- **Teléfonos de México**: 10 dígitos, con o sin `52` delante. Un número como `52 656286380` (9 dígitos después del 52) está incompleto: se carga sin teléfono y se avisa.
- **Teléfonos de Estados Unidos**: escribe `1 915 …` o agrega la columna País con `US`.
- Las filas vacías se ignoran.

Para guardarlo como CSV:
- Google Sheets: Archivo → Descargar → **Valores separados por comas (.csv)**.
- Excel: Guardar como → **CSV UTF-8 (delimitado por comas)**.

## 2. Instala y carga

1. Plugins → Añadir nuevo → Subir plugin → `mo-cargar-clientes-1.0.0.zip` → Activar.
2. Ve a **Reservas → Cargar clientes**, elige el archivo y da clic en **Revisar archivo**.
3. Revisa la tabla. Todavía no se guarda nada:
   - **Nuevo**: se crea el cliente.
   - **Ya existe con cambios**: solo se propone subir el número de sesiones o llenar un correo o teléfono vacío. El nombre nunca se cambia y las sesiones nunca bajan. Estos cambios solo se aplican si marcas la casilla.
   - **Repetido** (mismo correo o teléfono más arriba en el archivo), **Conflicto** (el correo es de un cliente y el teléfono de otro) y **Error** (sin nombre o sin contacto válido): no se cargan.
   - **Nombre parecido**: aviso para que revises si es la misma persona; no se fusiona.
4. Da clic en **Cargar N clientes**.

Con 3 sesiones o más, el cliente queda como frecuente (el umbral se cambia en Ajustes).

## 3. Si algo salió mal

En la misma pantalla aparece cada carga con **Deshacer**: borra los clientes que creó (salvo los que ya hicieron una reserva) y regresa los datos que cambió. El mismo archivo no se puede cargar dos veces.

## 4. Al terminar

Plugins → **Desactivar** y luego **Borrar** "MO Booking Studio – Cargar clientes (una vez)". Los clientes cargados se quedan.
