# Pruebas de navegador (Playwright)

Requieren un WordPress local con el plugin activo en `http://localhost:8089` (cambia con `BASE=`), un usuario `monica` / `clave-de-prueba-123`, la constante `MO_BOOKING_PRIVATE_DIR` apuntando a una carpeta fuera del sitio y una página con `[mo_booking service="prueba-minis-navidenas-2026"]`. Los scripts cargan los datos de prueba desde Ajustes.

```bash
npm install playwright@1.56.1
# Recorrido de preventa en teléfono (360 px) y teclado: AC-01, 02, 03, 04, 08, 18. Sale con código 1 si algo falla.
BOOKING_URL=http://localhost:8089/?page_id=N SHOTS=/tmp/capturas node preventa.js
# Panel: contrato versionado, edición con vista previa, archivar/restaurar, eliminar bloqueado, cancelar con destino del anticipo.
SHOTS=/tmp/capturas node admin-preventa.js
# Todas las pantallas del panel sin errores de PHP.
SHOTS=/tmp/capturas node admin-smoke.js
# Configurar un servicio completo solo desde el panel; tabla de precios por temporada.
SHOTS=/tmp/capturas node admin-config.js
BOOKING_URL=http://localhost:8089/?page_id=N SHOTS=/tmp/capturas node price-table.js
```

Si el sitio no tiene internet, WordPress tarda en el primer inicio de sesión porque busca actualizaciones; en un sitio local puedes bloquear `wordpress.org` con un mu-plugin de pruebas.

Estas pruebas se hicieron en un sitio local servido con `php -S`, **no** en LocalWP ni en el subdominio HTTPS. Lo que solo se valida en el subdominio está en `docs/entrega-preventa-beta2.md`.
