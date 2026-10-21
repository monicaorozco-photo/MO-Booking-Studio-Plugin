#!/usr/bin/env bash
# Genera dist/mo-booking-studio-X.Y.Z.zip con una sola carpeta raíz "mo-booking-studio".
# Incluye solo lo necesario para ejecutar el plugin (sin pruebas, Composer ni Node).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
VERSION="$(grep -m1 "define( 'MO_BOOKING_VERSION'" "$ROOT/mo-booking-studio.php" | sed -E "s/.*'([0-9A-Za-z.-]+)'.*/\1/")"
BUILD="$ROOT/build/mo-booking-studio"
rm -rf "$ROOT/build" && mkdir -p "$BUILD" "$ROOT/dist"
cp -r "$ROOT/mo-booking-studio.php" "$ROOT/uninstall.php" "$ROOT/src" "$ROOT/assets" "$ROOT/README.md" "$BUILD/"
mkdir -p "$BUILD/languages" "$BUILD/docs"
cp "$ROOT"/docs/*.md "$BUILD/docs/" 2>/dev/null || true
printf '<?php\n// Silencio.\n' > "$BUILD/index.php"
( cd "$ROOT/build" && rm -f "$ROOT/dist/mo-booking-studio-$VERSION.zip" && zip -qr "$ROOT/dist/mo-booking-studio-$VERSION.zip" mo-booking-studio -x '*.DS_Store' )
echo "$ROOT/dist/mo-booking-studio-$VERSION.zip"
