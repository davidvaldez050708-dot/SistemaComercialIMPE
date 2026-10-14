#!/usr/bin/env bash
# Habilita un diagnóstico PRIVADO de errores del historial de llamadas.
# Únicamente cambia prueba_telefonia/api/llamadas_seguimiento.php.
# No modifica SIP, Zadarma, WebRTC, CRM, historial comercial ni BD.
set -euo pipefail
umask 077

MODE="${1:---check}"
[[ "$MODE" == "--check" || "$MODE" == "--apply" ]] || {
  echo "Uso: bash $0 --check|--apply" >&2
  exit 2
}

SOURCE="${IMPE_DIAGNOSTICO_SOURCE:-$HOME/crm-preparacion}"
LIVE="${IMPE_DIAGNOSTICO_PUBLIC:-$HOME/domains/fundacionrededumex.org/public_html}"
BACKUPS="${IMPE_DIAGNOSTICO_BACKUPS:-$HOME/respaldos-crm}"
FILE="prueba_telefonia/api/llamadas_seguimiento.php"
LOG_DIR="$(dirname "$LIVE")/crm-diagnosticos"
LOG_FILE="$LOG_DIR/llamadas_seguimiento.log"
BASE="7f56c53d12ff35edbb9072d6ee97960b0fea88b5"
TARGET="5bab25f9ef274c10b08929d980bbfa9c09172b04"

[[ -d "$SOURCE/.git" && -d "$LIVE" && ! -L "$LIVE" ]] || {
  echo "ERROR: falta repositorio de preparación o directorio publicado." >&2
  exit 1
}
for revision in "$BASE" "$TARGET"; do
  git -C "$SOURCE" cat-file -e "${revision}^{commit}" || {
    echo "ERROR: faltan commits en crm-preparacion. Ejecuta git pull --ff-only origin main." >&2
    exit 1
  }
done
[[ -f "$LIVE/$FILE" && ! -L "$LIVE/$FILE" ]] || {
  echo "ERROR: archivo publicado faltante o enlace simbólico: $FILE" >&2
  exit 1
}
[[ ! -L "$LOG_DIR" && ! -L "$LOG_FILE" ]] || {
  echo "ERROR: la ruta de diagnósticos contiene un enlace simbólico." >&2
  exit 1
}

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
mkdir -p "$TMP/site/$(dirname "$FILE")"
cp -p "$LIVE/$FILE" "$TMP/site/$FILE"
ORIGINAL_SHA="$(sha256sum "$LIVE/$FILE" | cut -d' ' -f1)"
git -C "$SOURCE" diff --binary "$BASE" "$TARGET" -- "$FILE" > "$TMP/diagnostico.patch"
[[ -s "$TMP/diagnostico.patch" ]] || {
  echo "ERROR: el parche de diagnóstico está vacío." >&2
  exit 1
}

CHANGED=0
if (cd "$TMP/site" && git apply --reverse --check "$TMP/diagnostico.patch" >/dev/null 2>&1); then
  echo "YA ACTUALIZADO: $FILE"
elif (cd "$TMP/site" && git apply --check "$TMP/diagnostico.patch" >/dev/null 2>&1); then
  (cd "$TMP/site" && git apply "$TMP/diagnostico.patch")
  echo "PARCHE COMPATIBLE: $FILE"
  CHANGED=1
else
  echo "CONFLICTO: el archivo publicado tiene cambios incompatibles. Producción intacta." >&2
  exit 1
fi
php -l "$TMP/site/$FILE" >/dev/null
grep -Fq '/crm-diagnosticos/llamadas_seguimiento.log' "$TMP/site/$FILE"

if [[ "$MODE" == "--check" ]]; then
  echo "OK: diagnóstico compatible. PRODUCCIÓN INTACTA."
  echo "Se habilitará una bitácora privada FUERA de public_html: $LOG_FILE"
  echo "Para publicar, ejecuta --apply."
  exit 0
fi

# Preparar carpeta privada ANTES de sustituir el archivo del endpoint.
[[ ! -e "$LOG_DIR" || -d "$LOG_DIR" ]] || {
  echo "ERROR: ruta de diagnósticos no es directorio." >&2
  exit 1
}
mkdir -p "$LOG_DIR"
chmod 0700 "$LOG_DIR"
[[ ! -e "$LOG_FILE" || -f "$LOG_FILE" ]] || {
  echo "ERROR: la bitácora no es un archivo regular." >&2
  exit 1
}
[[ ! -L "$LOG_FILE" ]] || {
  echo "ERROR: la bitácora no puede ser un enlace simbólico." >&2
  exit 1
}
# PHP-FPM y SSH deben usar el mismo usuario del alojamiento.
test -w "$LOG_DIR" || {
  echo "ERROR: no se puede escribir en la carpeta privada." >&2
  exit 1
}

if [[ "$CHANGED" -eq 0 ]]; then
  echo "OK: endpoint ya actualizado y carpeta privada preparada."
  echo "Registro: $LOG_FILE"
  exit 0
fi
[[ "$(sha256sum "$LIVE/$FILE" | cut -d' ' -f1)" == "$ORIGINAL_SHA" ]] || {
  echo "CONFLICTO: el endpoint cambió durante el preflight." >&2
  exit 1
}

mkdir -p "$BACKUPS"
BACKUP="$BACKUPS/diagnostico-historial-$(date +%Y%m%d-%H%M%S)-$$"
mkdir -p "$BACKUP/$(dirname "$FILE")"
cp -p "$LIVE/$FILE" "$BACKUP/$FILE"
rollback() {
  status=$?
  trap - EXIT
  if [[ "$status" -ne 0 ]]; then
    cp -p "$BACKUP/$FILE" "$LIVE/$FILE"
    echo "ERROR: archivo restaurado desde $BACKUP." >&2
  fi
  exit "$status"
}
trap rollback EXIT
cp -p "$TMP/site/$FILE" "$LIVE/$FILE"
chmod 0644 "$LIVE/$FILE"
php -l "$LIVE/$FILE" >/dev/null
cmp -s "$TMP/site/$FILE" "$LIVE/$FILE"
trap - EXIT

echo "OK: diagnóstico habilitado. Respaldo: $BACKUP"
echo "Bitácora privada: $LOG_FILE"
echo "Intactos: WebRTC, Zadarma, Marketing, Seguimiento, BD y archivos JS."
