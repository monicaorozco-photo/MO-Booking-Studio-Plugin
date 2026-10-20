# Pruebas de navegador (Playwright)

Requieren un WordPress local con el plugin activo, servido en `http://localhost:8089`, y un usuario `monica` / `clave-de-prueba-123`.

```bash
npm install playwright
SHOTS=/tmp/capturas node admin-smoke.js     # abre todas las pantallas del panel y busca errores de PHP
SHOTS=/tmp/capturas node admin-config.js    # configura un servicio completo solo desde el panel
BOOKING_URL=http://localhost:8089/?page_id=N SHOTS=/tmp/capturas node flow.js   # recorrido completo en celular (360 px)
```

`flow.js` espera que se hayan cargado los datos de prueba (Ajustes → Crear datos de prueba) y que exista una página con `[mo_booking service="prueba-minis-navidenas-2026"]`.
