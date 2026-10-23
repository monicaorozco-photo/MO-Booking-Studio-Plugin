#!/usr/bin/env bash
# Genera dist/mo-cargar-clientes-X.Y.Z.zip (herramienta de carga única, aparte del plugin).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SRC="$ROOT/tools/mo-cargar-clientes"
VERSION="$(grep -m1 ' \* Version:' "$SRC/mo-cargar-clientes.php" | sed -E 's/.*Version: *//')"
BUILD="$ROOT/build/mo-cargar-clientes"
rm -rf "$BUILD" && mkdir -p "$BUILD" "$ROOT/dist"
cp "$SRC/mo-cargar-clientes.php" "$BUILD/"
cp "$ROOT/docs/cargar-clientes.md" "$BUILD/LEEME.md"
printf '<?php\n// Silencio.\n' > "$BUILD/index.php"
( cd "$ROOT/build" && rm -f "$ROOT/dist/mo-cargar-clientes-$VERSION.zip" && zip -qr "$ROOT/dist/mo-cargar-clientes-$VERSION.zip" mo-cargar-clientes )
( cd "$ROOT/dist" && sha256sum "mo-cargar-clientes-$VERSION.zip" > "mo-cargar-clientes-$VERSION.zip.sha256" )
echo "$ROOT/dist/mo-cargar-clientes-$VERSION.zip"
