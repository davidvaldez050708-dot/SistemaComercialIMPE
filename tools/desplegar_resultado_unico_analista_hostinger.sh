#!/usr/bin/env bash
# Corregir sólo la UI de llamadas SDK. No alterar PBX, shell ni MySQL.
set -euo pipefail
umask 077
MODE="${1:---check}"
[[ "$MODE" == "--check" || "$MODE" == "--apply" ]] || {
  echo "Uso: bash $0 --check|--apply" >&2; exit 2;
}
SOURCE="${IMPE_ONE_RESULT_SOURCE:-$HOME/crm-preparacion}"
LIVE="${IMPE_ONE_RESULT_PUBLIC:-$HOME/domains/fundacionrededumex.org/public_html}"
BACKUPS="${IMPE_ONE_RESULT_BACKUPS:-$HOME/respaldos-crm}"
FILE="public/javascript/telefonia_analista_historial_pasivo.js"
if [[ ! -f "$SOURCE/$FILE" || ! -f "$LIVE/index.php" ||
      ! -f "$LIVE/$FILE" || -L "$LIVE/$FILE" || -L "$LIVE" ]]; then
  echo "ERROR: faltan archivos regulares. PRODUCCIÓN INTACTA." >&2; exit 1
fi
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
cp -p "$LIVE/$FILE" "$TMP/stage.js"
BEFORE="$(sha256sum "$LIVE/$FILE" | cut -d' ' -f1)"
python3 - "$TMP/stage.js" <<'PY'
import pathlib
import sys
p=pathlib.Path(sys.argv[1])
try:
    s=p.read_text(encoding='utf-8')
    mark='impe-sdk-result-in-modal'
    css_start='    finishedCss.textContent = ['
    draw_start='    const draw = function () {'
    css_has="body.impe-sdk-result-in-modal [data-analyst-call-result]"
    draw_has="'impe-sdk-result-in-modal', Boolean(active || finished)"
    if css_has in s and draw_has in s:
        print('YA ACTUALIZADO: registrar sólo en modal de llamadas SDK')
        sys.exit(0)
    if mark in s:
        sys.exit('CONFLICTO: ocultación parcial/diferente. PRODUCCIÓN INTACTA.')
    if s.count(css_start)!=1 or s.count(draw_start)!=1:
        sys.exit('CONFLICTO: anclas del observador incompatibles. PRODUCCIÓN INTACTA.')
    if ("const panel = root.document.querySelector('[data-incoming-panel]');" not in s
            or "const resultBtn = addAction(" not in s):
        sys.exit('CONFLICTO: no es el observador SDK esperado. PRODUCCIÓN INTACTA.')
    css_new=css_start+'''
        // Evitar que el shell muestre otro Registrar resultado del SDK.
        'body.impe-sdk-result-in-modal [data-analyst-call-result] {' +
          'display:none!important;}','''
    draw_new=draw_start+'''
        // El estado SDK tiene un solo modal; no borrar un pendiente PBX.
        root.document.body?.classList?.toggle(
            'impe-sdk-result-in-modal', Boolean(active || finished)
        );'''
    s=s.replace(css_start,css_new,1)
    s=s.replace(draw_start,draw_new,1)
    p.write_text(s,encoding='utf-8')
    print('FUSIÓN COMPATIBLE: aviso inferior oculto sólo en llamadas SDK')
except (OSError,UnicodeError) as e:
    sys.exit('CONFLICTO: error leyendo el observador (%s). PRODUCCIÓN INTACTA.' %
             type(e).__name__)
PY
if command -v node >/dev/null 2>&1; then node --check "$TMP/stage.js"; fi
grep -Fq "impe-sdk-result-in-modal" "$TMP/stage.js"
grep -Fq "data-analyst-call-result" "$TMP/stage.js"
if cmp -s "$LIVE/$FILE" "$TMP/stage.js"; then
  echo "OK: resultado único en modal ya actualizado."
  exit 0
fi
if [[ "$MODE" == "--check" ]]; then
  echo "OK: resultado único del Analista compatible. PRODUCCIÓN INTACTA."
  echo "Sólo cambiará $FILE; shell, Zadarma, datos y grabaciones intactos."
  exit 0
fi
if [[ "$(sha256sum "$LIVE/$FILE" | cut -d' ' -f1)" != "$BEFORE" ]]; then
  echo "CONFLICTO: el observador cambió durante la comprobación. PRODUCCIÓN INTACTA." >&2
  exit 1
fi
mkdir -p "$BACKUPS"
BACKUP="$BACKUPS/analista-resultado-unico-$(date +%Y%m%d-%H%M%S)-$$"
mkdir -p "$BACKUP/$(dirname "$FILE")"
cp -p "$LIVE/$FILE" "$BACKUP/$FILE"
rollback() {
  rc=$?
  trap - EXIT
  if [[ "$rc" -ne 0 ]]; then
    cp -p "$BACKUP/$FILE" "$LIVE/$FILE"
    echo "ERROR: restaurado $BACKUP" >&2
  fi
  exit "$rc"
}
trap rollback EXIT
cp -p "$TMP/stage.js" "$LIVE/$FILE"
chmod 0644 "$LIVE/$FILE"
cmp -s "$TMP/stage.js" "$LIVE/$FILE"
if command -v node >/dev/null 2>&1; then node --check "$LIVE/$FILE"; fi
trap - EXIT
echo "OK: resultado único en modal publicado. Respaldo: $BACKUP"
