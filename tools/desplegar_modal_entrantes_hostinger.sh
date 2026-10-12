#!/usr/bin/env bash
# Publicación conservadora del modal CRM para entrantes.
# --check prepara y valida sin escribir en producción; --apply respalda y publica.
set -euo pipefail
umask 077
MODE="--check"
if [[ "$#" -gt 0 ]]; then MODE="$1"; fi
[[ "$MODE" == "--check" || "$MODE" == "--apply" ]] || {
  echo "Uso: bash tools/desplegar_modal_entrantes_hostinger.sh --check|--apply" >&2
  exit 2
}
SRC="$HOME/crm-preparacion"
DST="$HOME/domains/fundacionrededumex.org/public_html"
BACKUPS="$HOME/respaldos-crm"
BASE_REV="00e79124af6b77459e79fd8f47437ac2c2eaf1dc"
FILES="public/javascript/telefonia_entrantes_bridge.js public/javascript/telefonia_host.js public/javascript/telefonia_shell.js public/css/telefonia_shell.css app/views/telefonia/host.php app/views/telefonia/shell.php"
[[ -d "$SRC/.git" && -f "$DST/index.php" ]] || {
  echo "ERROR: no se encontró el repositorio o el sitio público." >&2; exit 1
}
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
echo "1/4 Preparando seis archivos sin modificar Hostinger..."
for p in $FILES; do
  [[ -f "$SRC/$p" ]] || { echo "ERROR: falta $p en Git." >&2; exit 1; }
  mkdir -p "$TMP/$(dirname "$p")"
  if [[ "$p" == "public/javascript/telefonia_entrantes_bridge.js" ]]; then
    if [[ ! -f "$DST/$p" ]]; then
      cp "$SRC/$p" "$TMP/$p"
      continue
    fi
    # En producción ya existe una versión anterior del puente.
    # Fusionar sus posibles personalizaciones, no rechazar por cambiar Git.
    if cmp -s "$DST/$p" "$SRC/$p"; then
      cp "$DST/$p" "$TMP/$p"
      continue
    fi
    git -C "$SRC" show "39f306fd998de860a515df9222700669b2d96dab:$p" > "$TMP/base" || {
      echo "ERROR: falta historial del puente anterior." >&2; exit 1
    }
    if ! git merge-file -p "$DST/$p" "$TMP/base" "$SRC/$p" > "$TMP/$p"; then
      echo "CONFLICTO: puente Zadarma personalizado, no se publicó nada." >&2
      exit 1
    fi
    continue
  fi
  [[ -f "$DST/$p" ]] || { echo "ERROR: falta el archivo público $p." >&2; exit 1; }
  if cmp -s "$SRC/$p" "$DST/$p"; then
    cp "$DST/$p" "$TMP/$p"
    continue
  fi
  git -C "$SRC" show "$BASE_REV:$p" > "$TMP/base" || {
    echo "ERROR: falta la base histórica de Git para $p." >&2; exit 1
  }
  if ! git merge-file -p "$DST/$p" "$TMP/base" "$SRC/$p" > "$TMP/$p"; then
    # La producción también puede partir del modal anterior ya publicado.
    # Reintentar la fusión con esa versión exacta, nunca sobrescribir
    # ciegamente un archivo con cambios locales de Hostinger.
    git -C "$SRC" show "39f306fd998de860a515df9222700669b2d96dab:$p" > "$TMP/base-reciente" || {
      echo "ERROR: falta la base Git del modal anterior." >&2; exit 1
    }
    if ! git merge-file -p "$DST/$p" "$TMP/base-reciente" "$SRC/$p" > "$TMP/$p"; then
      echo "CONFLICTO: $p tiene personalizaciones incompatibles. No se publicó nada." >&2
      exit 1
    fi
  fi
done
echo "2/4 Validando PHP, JavaScript y enlace real a Zadarma..."
grep -Fq 'data-incoming-answer' "$TMP/app/views/telefonia/shell.php"
grep -Fq 'persistent-phone-panel impe-incoming-panel' "$TMP/app/views/telefonia/shell.php"
grep -Fq 'impePersistentPhoneStyles' "$TMP/app/views/telefonia/shell.php"
grep -Fq 'data-incoming-drag-handle' "$TMP/app/views/telefonia/shell.php"
grep -Fq 'data-incoming-answer-compact' "$TMP/app/views/telefonia/shell.php"
if grep -Fq 'data-incoming-provider' "$TMP/app/views/telefonia/shell.php"; then
  echo "ERROR: regresó el enlace innecesario a Zadarma" >&2; exit 1
fi
grep -Fq 'telefonia_entrantes_bridge.js' "$TMP/app/views/telefonia/host.php"
grep -Fq 'IMPE_ZADARMA_INCOMING' "$TMP/public/javascript/telefonia_host.js"
grep -Fq "providerBridge()?.answer()" "$TMP/public/javascript/telefonia_shell.js"
grep -Fq 'const customVisible = incoming' "$TMP/public/javascript/telefonia_shell.js"
php -l "$TMP/app/views/telefonia/shell.php"
php -l "$TMP/app/views/telefonia/host.php"
if command -v node >/dev/null 2>&1; then
  for p in public/javascript/telefonia_entrantes_bridge.js public/javascript/telefonia_host.js public/javascript/telefonia_shell.js; do
    node --check "$TMP/$p"
  done
fi
CHANGED=""
for p in $FILES; do
  if [[ ! -f "$DST/$p" ]] || ! cmp -s "$TMP/$p" "$DST/$p"; then
    CHANGED="$CHANGED $p"
  fi
done
if [[ -z "$CHANGED" ]]; then
  echo "OK: recepción CRM ya actualizada. Sin cambios."
  exit 0
fi
echo "Archivos para publicar:$CHANGED"
if [[ "$MODE" == "--check" ]]; then
  echo "OK: comprobación previa sin modificar el CRM público."
  exit 0
fi
echo "3/4 Respaldando archivos originales y registrando nuevos..."
mkdir -p "$BACKUPS"
BACKUP="$(mktemp -d "$BACKUPS/recepcion-crm-$(date +%Y%m%d-%H%M%S)-XXXXXX")"
for p in $CHANGED; do
  if [[ -f "$DST/$p" ]]; then
    mkdir -p "$BACKUP/$(dirname "$p")"
    cp -p "$DST/$p" "$BACKUP/$p"
  else
    echo "$p" >> "$BACKUP/archivos-nuevos.txt"
  fi
done
echo "4/4 Publicando únicamente la interfaz de recepción..."
for p in $CHANGED; do
  mkdir -p "$DST/$(dirname "$p")"
  cp "$TMP/$p" "$DST/$p"
done
for p in $FILES; do
  cmp -s "$TMP/$p" "$DST/$p" || {
    echo "ERROR: no coincide $p. Respaldo: $BACKUP" >&2
    exit 1
  }
done
echo "OK: modal entrante publicado; Zadarma y llamadas salientes no modificados."
echo "Respaldo: $BACKUP"
echo "Cierra todas las pestañas del CRM y vuelve a iniciar Marketing."
