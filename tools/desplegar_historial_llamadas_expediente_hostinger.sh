#!/usr/bin/env bash
# Sólo repara el historial telefónico del expediente (API JSON + render).
# No toca Zadarma, SIP, WebRTC, extensiones, base de datos ni Marketing.
set -euo pipefail
umask 077

MODE="${1:---check}"
if [[ "$MODE" != "--check" && "$MODE" != "--apply" ]]; then
  echo "Uso: bash $0 --check|--apply" >&2
  exit 2
fi

SOURCE="${IMPE_HISTORIAL_SOURCE:-$HOME/crm-preparacion}"
LIVE="${IMPE_HISTORIAL_PUBLIC:-$HOME/domains/fundacionrededumex.org/public_html}"
BACKUPS="${IMPE_HISTORIAL_BACKUPS:-$HOME/respaldos-crm}"
API="prueba_telefonia/api/llamadas_seguimiento.php"
JS="public/javascript/seguimiento_llamadas_expediente.js"
BASE_API="35bd6f6269426adb0c09530ffa67d9b281214924"
TARGET_API="00bb0597d6932b255c895f90dc69dab83acc2710"
BASE_JS="4ce7e5d74f30bc6a4ed108cbeaebaf0d8fc36e0e"
TARGET_JS="2773dcf6c827a506bc6ab8046592ad0b3bf44c29"

[[ -d "$SOURCE/.git" && -d "$LIVE" ]] || {
  echo "ERROR: falta repositorio de preparación o sitio público." >&2
  exit 1
}
for revision in "$BASE_API" "$TARGET_API" "$BASE_JS" "$TARGET_JS"; do
  git -C "$SOURCE" cat-file -e "$revision^{commit}" || {
    echo "ERROR: faltan commits. Ejecuta git -C \"$SOURCE\" pull --ff-only origin main." >&2
    exit 1
  }
done

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
mkdir -p "$TMP/stage" "$TMP/patches"
declare -a CHANGED=()
declare -A ORIGINAL_SHA=()
for file in "$API" "$JS"; do
  [[ -f "$LIVE/$file" && ! -L "$LIVE/$file" ]] || {
    echo "ERROR: no existe o es enlace simbólico: $file" >&2
    exit 1
  }
  ORIGINAL_SHA["$file"]="$(sha256sum "$LIVE/$file" | cut -d' ' -f1)"
  mkdir -p "$TMP/stage/$(dirname "$file")"
  cp -p "$LIVE/$file" "$TMP/stage/$file"
  if [[ "$file" == "$API" ]]; then
    git -C "$SOURCE" diff --binary "$BASE_API" "$TARGET_API" -- "$file" > "$TMP/patches/api.patch"
    patch="$TMP/patches/api.patch"
  else
    git -C "$SOURCE" diff --binary "$BASE_JS" "$TARGET_JS" -- "$file" > "$TMP/patches/js.patch"
    patch="$TMP/patches/js.patch"
  fi
  [[ -s "$patch" ]] || { echo "ERROR: parche vacío para $file" >&2; exit 1; }
  if (cd "$TMP/stage" && git apply --reverse --check "$patch" >/dev/null 2>&1); then
    echo "YA ACTUALIZADO: $file"
  elif (cd "$TMP/stage" && git apply --check "$patch" >/dev/null 2>&1); then
    (cd "$TMP/stage" && git apply "$patch")
    CHANGED+=("$file")
    echo "PARCHE COMPATIBLE: $file"
  else
    echo "CONFLICTO en $file: producción no se modifica." >&2
    exit 1
  fi
done

php -l "$TMP/stage/$API" >/dev/null || {
  echo "ERROR: el API resultante no pasó php -l." >&2; exit 1;
}
grep -Fq "llamadas_seguimiento:" "$TMP/stage/$API" || {
  echo "ERROR: falta registro de diagnóstico del API." >&2; exit 1;
}
grep -Fq "const cuerpo = await response.text();" "$TMP/stage/$JS" || {
  echo "ERROR: falta lectura segura de JSON." >&2; exit 1;
}
if command -v node >/dev/null 2>&1; then
  node --check "$TMP/stage/$JS" >/dev/null
fi

if [[ "${#CHANGED[@]}" -eq 0 ]]; then
  echo "OK: historial de llamadas del expediente ya corregido."
  exit 0
fi
if [[ "$MODE" == "--check" ]]; then
  echo "OK: parches compatibles. PRODUCCIÓN INTACTA."
  echo "Intactos: Zadarma, WebRTC, Recepción, Marketing, Seguimiento y BD."
  echo "Ejecuta --apply para publicar sólo los 2 archivos."
  exit 0
fi

for file in "${CHANGED[@]}"; do
  [[ "$(sha256sum "$LIVE/$file" | cut -d' ' -f1)" == "${ORIGINAL_SHA[$file]}" ]] || {
    echo "CONFLICTO: $file cambió después de la comprobación." >&2
    exit 1
  }
done
mkdir -p "$BACKUPS"
BACKUP="$BACKUPS/historial-llamadas-expediente-$(date +%Y%m%d-%H%M%S)-$$"
mkdir -p "$BACKUP"
for file in "${CHANGED[@]}"; do
  mkdir -p "$BACKUP/$(dirname "$file")"
  cp -p "$LIVE/$file" "$BACKUP/$file"
done
rollback() {
  code=$?
  trap - EXIT
  if [[ "$code" -ne 0 ]]; then
    for file in "${CHANGED[@]}"; do
      cp -p "$BACKUP/$file" "$LIVE/$file"
    done
    echo "ERROR: publicación revertida al respaldo $BACKUP" >&2
  fi
  exit "$code"
}
trap rollback EXIT
for file in "${CHANGED[@]}"; do
  cp -p "$TMP/stage/$file" "$LIVE/$file"
  chmod 0644 "$LIVE/$file"
  cmp -s "$TMP/stage/$file" "$LIVE/$file"
done
php -l "$LIVE/$API" >/dev/null
if command -v node >/dev/null 2>&1; then node --check "$LIVE/$JS" >/dev/null; fi
trap - EXIT
echo "OK: historial telefónico actualizado. Respaldo: $BACKUP"
echo "No se modificaron el motor telefónico ni la base de datos."
