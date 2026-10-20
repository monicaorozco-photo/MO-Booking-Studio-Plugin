#!/usr/bin/env bash
# Prepara un WordPress de pruebas para las pruebas de integración.
# Uso: WP_DIR=/ruta/wordpress DB_HOST=127.0.0.1 DB_USER=root DB_PASS=root bin/test-env.sh
set -euo pipefail
WP_DIR="${WP_DIR:?Define WP_DIR con la carpeta de WordPress}"
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-root}"
DB_NAME="${DB_NAME:-wp_test}"
PHP_BIN="${PHP_BIN:-php}"
PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"

cat > "$WP_DIR/wp-config.php" <<CFG
<?php
define( 'DB_NAME', '$DB_NAME' );
define( 'DB_USER', '$DB_USER' );
define( 'DB_PASSWORD', '$DB_PASS' );
define( 'DB_HOST', '$DB_HOST' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
define( 'AUTH_KEY', 'test-auth' ); define( 'SECURE_AUTH_KEY', 'test-secure-auth' );
define( 'LOGGED_IN_KEY', 'test-logged' ); define( 'NONCE_KEY', 'test-nonce' );
define( 'AUTH_SALT', 'test-auth-salt' ); define( 'SECURE_AUTH_SALT', 'test-secure-salt' );
define( 'LOGGED_IN_SALT', 'test-logged-salt' ); define( 'NONCE_SALT', 'test-nonce-salt' );
\$table_prefix = getenv( 'MO_TABLE_PREFIX' ) ?: 'wp_';
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_DISPLAY', false );
define( 'WP_DEBUG_LOG', true );
define( 'DISABLE_WP_CRON', true );
if ( getenv( 'MO_PRIVATE_DIR' ) ) { define( 'MO_BOOKING_PRIVATE_DIR', getenv( 'MO_PRIVATE_DIR' ) ); }
if ( ! defined( 'WP_HOME' ) ) { define( 'WP_HOME', getenv( 'MO_WP_URL' ) ?: 'http://localhost:8089' ); define( 'WP_SITEURL', WP_HOME ); }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
require_once ABSPATH . 'wp-settings.php';
CFG

mkdir -p "$WP_DIR/wp-content/plugins"
ln -sfn "$PLUGIN_DIR" "$WP_DIR/wp-content/plugins/mo-booking-studio"

"$PHP_BIN" -r "
\$m = new mysqli('$DB_HOST', '$DB_USER', '$DB_PASS');
\$m->query('CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4');
"
echo "WordPress de pruebas listo en $WP_DIR (base $DB_NAME)"
