#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
BASE="1d130d13b7c3a12d7affe5d4d5d6a2fcc5a7d7de"
FILES=(
 app/views/telefonia/shell.php
 app/views/layout/topbar.php
 public/javascript/telefonia_shell.js
 public/javascript/telefonia_persistente.js
)
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
export IMPE_RESTORE_SOURCE="$PWD"
export IMPE_RESTORE_BACKUPS="$TMP/backups"

for revision in \
  "35414e88bf88e2752d5bd821e0242edd9d644e48" \
  "13a8ed2a48a615620cf2480829fb474898bdd4be"; do
  public="$TMP/public-$revision"
  mkdir -p "$public"
  git archive "$BASE" | tar -xf - -C "$public"
  for file in "${FILES[@]}"; do
    git show "$revision:$file" > "$public/$file"
  done
  export IMPE_RESTORE_PUBLIC="$public"
  cp "$public/app/controllers/TelefoniaController.php" "$TMP/untouched.php"
  bash tools/restaurar_recepcion_webRTC_hostinger.sh --check > "$TMP/check.log"
  grep -Fq "reversión selectiva posible" "$TMP/check.log"
  git show "$revision:public/javascript/telefonia_shell.js" |
    cmp -s - "$public/public/javascript/telefonia_shell.js"
  bash tools/restaurar_recepcion_webRTC_hostinger.sh --apply
  for file in "${FILES[@]}"; do
    git show "$BASE:$file" | cmp -s - "$public/$file"
  done
  cmp -s "$TMP/untouched.php" "$public/app/controllers/TelefoniaController.php"
  bash tools/restaurar_recepcion_webRTC_hostinger.sh --check | grep -Fq 'ya coinciden'
done
# Simular una personalización incompatible en producción: no debe tocarla.
public="$TMP/conflict"
mkdir -p "$public"
git archive "$BASE" | tar -xf - -C "$public"
for file in "${FILES[@]}"; do
  git show "35414e88bf88e2752d5bd821e0242edd9d644e48:$file" > "$public/$file"
done
export IMPE_RESTORE_PUBLIC="$public"
sed -i "s/nativeAnalystOpen = true;/nativeAnalystOpen = 'CUSTOM';/" \
  "$public/public/javascript/telefonia_shell.js"
cp "$public/public/javascript/telefonia_shell.js" "$TMP/conflict-original"
if bash tools/restaurar_recepcion_webRTC_hostinger.sh --apply >"$TMP/error.log" 2>&1; then
  echo "ERROR: la reversión aceptó un archivo modificado." >&2
  exit 1
fi
grep -Fq 'CONFLICTO' "$TMP/error.log"
cmp -s "$TMP/conflict-original" "$public/public/javascript/telefonia_shell.js"
echo "Recuperación WebRTC: OK (dos versiones, solo lectura, respaldo, rechazo de conflictos)."
