# Pruebas automatizadas

Todas se ejecutan desde la raíz del repositorio. Las de integración usan un WordPress real con su propia base de datos de pruebas (nunca la de tu sitio).

## 1. Dependencias

- PHP 7.4 o 8.x con `mysqli`, `mbstring`, `sodium` y, de preferencia, `gd`.
- Composer.
- MariaDB o MySQL (por ejemplo con Docker).
- Node 18+ y Playwright solo para las pruebas de navegador.

```bash
composer install                      # instala PHPUnit 9.6 (vendor/)
docker run -d --name mo-db -p 3306:3306 -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=wp_test mariadb:10.11
```

## 2. WordPress de pruebas

```bash
mkdir -p /tmp/wp && curl -sL https://wordpress.org/latest.tar.gz | tar xz -C /tmp/wp --strip-components=1
WP_DIR=/tmp/wp DB_HOST=127.0.0.1 DB_USER=root DB_PASS=root bin/test-env.sh
```

`bin/test-env.sh` escribe el `wp-config.php` de pruebas y enlaza el plugin en `wp-content/plugins/mo-booking-studio`.

## 3. Ejecutar

```bash
vendor/bin/phpunit --testsuite unit                       # sin WordPress
MO_WP_DIR=/tmp/wp vendor/bin/phpunit                      # unitarias + integración
```

En PHP 7.4 con Docker (mismo WordPress y base de datos):

```bash
docker run --rm --network host -v "$PWD":"$PWD" -v /tmp/wp:/tmp/wp -w "$PWD" -e MO_WP_DIR=/tmp/wp php:7.4-cli \
  sh -c "docker-php-ext-install mysqli >/dev/null && php vendor/bin/phpunit"
```

La prueba de recodificación de imágenes se omite si PHP no tiene GD (sin GD, el panel solo ofrece descargar la imagen).

## Qué cubren

| Archivo | Cubre |
|---|---|
| `Integration/StorageSecurityTest.php` | SEG-01 carpeta privada (rutas protegidas, marcador, TLS, URL exacta) y SEG-02 desinstalación segura |
| `Integration/BookingFormTest.php` | Formulario 6.3/6.4: niños + adultos, mascotas, validación, entrega rápida congelada, información importante, instantánea de preguntas |
| `Integration/ContractTest.php` | 6.5 y AC-08: versiones, copia congelada, aceptación obligatoria, HTML permitido |
| `Integration/AdminActionsTest.php` | AC-13 archivo/eliminación, AC-14 reversos, cancelación con destino del anticipo, edición con auditoría, corrección con excepción |
| `Integration/GoogleCalendarTest.php` | Google simulado: AC-03, 06, 07, 09, 10, 11, 12, SEG-03 invitación, SEG-05 migración de destino |
| `Integration/ProofReviewTest.php` | AC-05 corrección, comprobante sustituido, archivo huérfano |
| `Integration/FrequentClientTest.php` | SEG-04 Salida B (sin precio frecuente automático) |
| `Integration/MigrationTest.php` | AC-17 actualización 0.1.1 → 3 y beta.1 → beta.2 sin pérdida de datos |
| `Integration/ConcurrencyTest.php` | AC-02 con dos procesos PHP reales |
| `Unit/*` | Motores de precios, disponibilidad, políticas, contactos y libro de movimientos |

Google se simula con `Fixtures/FakeGoogle.php` (filtro `pre_http_request`): ninguna prueba se conecta a Google. Eso **no** sustituye la prueba con una cuenta real (ver `docs/pruebas-google.md`).

## Pruebas de navegador

Ver `tests/e2e/README.md`.
