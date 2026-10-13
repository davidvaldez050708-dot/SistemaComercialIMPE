#!/usr/bin/env bash
# Sólo interfaz de llamadas salientes en el shell existente: sin segundo SIP.
# --check no escribe producción. --apply respalda cada archivo antes de publicarlo.
set -euo pipefail
umask 077
MODE="${1:---check}"
[[ "$MODE" == "--check" || "$MODE" == "--apply" ]] || {
  echo "Uso: bash tools/desplegar_salientes_modal_hostinger.sh --check|--apply" >&2
  exit 2
}
SRC="${IMPE_SALIENTES_SOURCE:-$HOME/crm-preparacion}"
LIVE="${IMPE_SALIENTES_PUBLIC:-$HOME/domains/fundacionrededumex.org/public_html}"
BACKUPS="${IMPE_SALIENTES_BACKUP:-$HOME/respaldos-crm}"
BASE="8fc7f1dad44cefe6c070da89d48beb10ed5bed9f"
FILES=(
 "public/javascript/telefonia_shell.js"
 "public/javascript/telefonia_persistente.js"
 "public/javascript/telefonia_teclado.js"
 "public/css/telefonia_teclado.css"
)
[[ -d "$SRC/.git" && -f "$LIVE/index.php" ]] || {
  echo "ERROR: no se encontró el repositorio o la instalación pública." >&2; exit 1
}
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
echo "1/4 Preparando cambios sin modificar Hostinger..."
for p in "${FILES[@]}"; do
  [[ -f "$SRC/$p" && -f "$LIVE/$p" && ! -L "$LIVE/$p" ]] || {
    echo "ERROR: falta archivo seguro $p" >&2; exit 1
  }
  mkdir -p "$TMP/$(dirname "$p")"
  if [[ "$p" == "public/css/telefonia_teclado.css" ]]; then
    # Esta entrega sólo añade la regla para ocultar el aviso innecesario.
    # No fusionar ni sustituir el resto del CSS personalizado por Hostinger.
    if grep -Fq '.impe-dialer-state[hidden] {display:none !important;}' "$LIVE/$p"; then
      cp "$LIVE/$p" "$TMP/$p"
    elif grep -Fq '.impe-dialer-state[hidden]' "$LIVE/$p"; then
      echo "CONFLICTO: regla personalizada para ocultar el aviso del marcador." >&2
      exit 1
    else
      cp "$LIVE/$p" "$TMP/$p"
      printf '\n/* No mostrar aviso de disponibilidad al abrir el teléfono. */\n.impe-dialer-state[hidden] {display:none !important;}\n' >> "$TMP/$p"
    fi
    continue
  fi
  if [[ "$p" == "public/javascript/telefonia_persistente.js" ]]; then
    # Sólo injertar dos comportamientos revisados; conservar el JS de
    # producción byte por byte fuera de esas operaciones.
    php "$SRC/tools/fusionar_salientes_persistente.php" \
      "$SRC/$p" "$LIVE/$p" "$TMP/$p" || {
        echo "CONFLICTO: integración selectiva no autorizada. Sin publicar." >&2
        exit 1
      }
    continue
  fi
  git -C "$SRC" show "$BASE:$p" > "$TMP/base" || {
    echo "ERROR: falta base histórica para $p." >&2; exit 1
  }
  if cmp -s "$LIVE/$p" "$SRC/$p" || cmp -s "$LIVE/$p" "$TMP/base"; then
    cp "$SRC/$p" "$TMP/$p"
  elif git merge-file -p -L HOSTINGER -L BASE -L GITHUB \
        "$LIVE/$p" "$TMP/base" "$SRC/$p" > "$TMP/$p"; then
    echo "Fusionada personalización local: $p"
  else
    echo "CONFLICTO: $p contiene modificaciones incompatibles. No se publicó nada." >&2
    exit 1
  fi
done
echo "2/4 Validando los controles telefónicos..."
check() {
 grep -Fq "$2" "$TMP/$1" || {
   echo "ERROR: $1 no contiene control requerido: $2. Sin publicar." >&2; exit 1
 }
}
check public/javascript/telefonia_persistente.js "getHostMode() === 'embedded'"
check public/javascript/telefonia_persistente.js 'shell.ensureHost();'
check public/javascript/telefonia_persistente.js 'await prepare({ openHost: false });'
check public/javascript/telefonia_shell.js 'singleCallPanel: true'
check public/javascript/telefonia_shell.js 'const shownOutgoing ='
check public/javascript/telefonia_shell.js 'pendingOutgoingHangup'
check public/javascript/telefonia_shell.js 'providerBridge()?.answer()'
check public/javascript/telefonia_teclado.js 'handoffToCall();'
check public/javascript/telefonia_teclado.js 'onCallRequested?.({number:phone})'
check public/css/telefonia_teclado.css '.impe-dialer-state[hidden] {display:none !important;}'
if command -v node >/dev/null 2>&1; then
 for p in public/javascript/telefonia_persistente.js \
          public/javascript/telefonia_shell.js \
          public/javascript/telefonia_teclado.js; do
   node --check "$TMP/$p"
 done
fi
CHANGED=()
for p in "${FILES[@]}"; do
  cmp -s "$TMP/$p" "$LIVE/$p" || CHANGED+=("$p")
done
if [[ "${#CHANGED[@]}" -eq 0 ]]; then
  echo "OK: panel saliente ya publicado. Sin cambios."
  exit 0
fi
printf 'Archivos pendientes: %s\n' "${CHANGED[@]}"
if [[ "$MODE" == "--check" ]]; then
  echo "OK: comprobación previa sin modificar producción."
  exit 0
fi
echo "3/4 Respaldando los archivos originales..."
mkdir -p "$BACKUPS"
BACKUP="$(mktemp -d "$BACKUPS/salientes-panel-$(date +%Y%m%d-%H%M%S)-XXXXXX")"
for p in "${CHANGED[@]}"; do
 mkdir -p "$BACKUP/$(dirname "$p")"
 cp -p "$LIVE/$p" "$BACKUP/$p"
done
echo "4/4 Publicando sólo los archivos comprobados..."
for p in "${CHANGED[@]}"; do
  cp "$TMP/$p" "$LIVE/$p"
  cmp -s "$TMP/$p" "$LIVE/$p" || {
    echo "ERROR: $p no coincide. Respaldo: $BACKUP" >&2
    exit 1
  }
done
echo "OK: panel saliente único publicado. Sin cambios en BD ni Zadarma."
echo "Respaldo: $BACKUP"
echo "Cierra todas las sesiones del CRM antes de comprobar."
