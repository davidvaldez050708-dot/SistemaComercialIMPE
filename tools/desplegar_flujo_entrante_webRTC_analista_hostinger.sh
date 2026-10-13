#!/usr/bin/env bash
# Corrige el ciclo de vida WebRTC de llamadas INTERNAS en el modal del Analista.
# No cambia el SDK del proveedor, Marketing, PBX, webhook, MySQL ni shell.
# Compatibilidad: diseñado para producción con detector SIP ya publicado.
set -euo pipefail
umask 077

MODE="${1:---check}"
if [[ "$MODE" != "--check" && "$MODE" != "--apply" ]]; then
  echo "Uso: bash tools/desplegar_flujo_entrante_webRTC_analista_hostinger.sh --check|--apply" >&2
  exit 2
fi

SOURCE="${IMPE_FLOW_SOURCE:-$HOME/crm-preparacion}"
PUBLIC="${IMPE_FLOW_PUBLIC:-$HOME/domains/fundacionrededumex.org/public_html}"
BACKUPS="${IMPE_FLOW_BACKUPS:-$HOME/respaldos-crm}"

# Versión anterior con detección de ringing, ya comprobada por el usuario.
BEFORE="6e426e4612b3457fccdb7b8d90cc72549826e686"
# Versión corregida que usa el propio modal para Contestar/Silenciar/Colgar.
AFTER="9c6af2f292f2bde1a01b0aeb0cb88106327518c0"
ENGINE="public/javascript/telefonia_host.js"
OBSERVER="public/javascript/telefonia_sdk_entrantes_observador.js"

[[ -d "$SOURCE/.git" && -d "$PUBLIC" ]] || {
  echo "ERROR: No se encontró el repositorio o public_html." >&2
  exit 1
}
for revision in "$BEFORE" "$AFTER"; do
  git -C "$SOURCE" cat-file -e "${revision}^{commit}" || {
    echo "ERROR: actualiza ~/crm-preparacion con git pull --ff-only origin main." >&2
    exit 1
  }
done
for file in "$ENGINE" "$OBSERVER"; do
  [[ -f "$PUBLIC/$file" && ! -L "$PUBLIC/$file" ]] || {
    echo "ERROR: falta archivo publicado o es enlace simbólico: $file." >&2
    exit 1
  }
done

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
git -C "$SOURCE" diff --binary "$BEFORE" "$AFTER" -- "$ENGINE" "$OBSERVER" > "$TMP/update.patch"
[[ -s "$TMP/update.patch" ]] || {
  echo "ERROR: no se pudo generar el parche." >&2
  exit 1
}

# Primero evaluar sin cambios. Nunca hacer checkout de producción ni
# reemplazar archivos que pudieron recibir modificaciones independientes.
if (cd "$PUBLIC" && git apply --reverse --check "$TMP/update.patch" >/dev/null 2>&1); then
  if [[ "$MODE" == "--apply" ]]; then
    chmod 0644 "$PUBLIC/$ENGINE" "$PUBLIC/$OBSERVER"
  fi
  echo "OK: el flujo completo de llamada entrante ya está actualizado."
  exit 0
fi
if ! (cd "$PUBLIC" && git apply --check "$TMP/update.patch" >/dev/null 2>&1); then
  echo "CONFLICTO: producción tiene una versión o modificaciones distintas." >&2
  echo "No se modificó nada; comparte esta salida para preparar un parche compatible." >&2
  exit 1
fi

if [[ "$MODE" == "--check" ]]; then
  echo "OK: parche compatible para las llamadas entrantes del Analista."
  echo "Sin modificaciones. Continúa con --apply."
  exit 0
fi

mkdir -p "$BACKUPS"
BACKUP="$BACKUPS/flujo-entrante-analista-$(date +%Y%m%d-%H%M%S)-$$"
mkdir -p "$BACKUP/public/javascript"
cp -p "$PUBLIC/$ENGINE" "$BACKUP/$ENGINE"
cp -p "$PUBLIC/$OBSERVER" "$BACKUP/$OBSERVER"

rollback() {
  local result=$?
  trap - EXIT
  if [[ "$result" -ne 0 ]]; then
    cp -p "$BACKUP/$ENGINE" "$PUBLIC/$ENGINE"
    cp -p "$BACKUP/$OBSERVER" "$PUBLIC/$OBSERVER"
    echo "ERROR: revertido a los respaldos: $BACKUP" >&2
  fi
  rm -rf "$TMP"
  exit "$result"
}
trap rollback EXIT

(cd "$PUBLIC" && git apply "$TMP/update.patch")
# Evitar errores HTTP 403 del JavaScript público en Hostinger.
chmod 0644 "$PUBLIC/$ENGINE" "$PUBLIC/$OBSERVER"
(cd "$PUBLIC" && git apply --reverse --check "$TMP/update.patch")
echo "OK: Contestar, cronómetro, Silenciar y Colgar sincronizados con el modal."
echo "Respaldo: $BACKUP"
echo "Intactos: Marketing, PBX, webhooks, shell, base de datos y Seguimiento."
