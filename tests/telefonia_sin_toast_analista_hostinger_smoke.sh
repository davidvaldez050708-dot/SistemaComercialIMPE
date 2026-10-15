#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
export IMPE_TOAST_PUBLIC="$TMP/live"
export IMPE_TOAST_BACKUPS="$TMP/backups"
TARGET="public/javascript/telefonia_analista_historial_pasivo.js"
mkdir -p "$IMPE_TOAST_PUBLIC/public/javascript"
touch "$IMPE_TOAST_PUBLIC/index.php"
# Simular la versión previa del GitHub y la variante personalizada de Hostinger.
git show "9cfa02e249127b1fcf13dbd4c11d73ebb059cd75:$TARGET" > "$IMPE_TOAST_PUBLIC/$TARGET"
python3 - "$IMPE_TOAST_PUBLIC/$TARGET" <<'PY'
from pathlib import Path
import sys
p=Path(sys.argv[1])
s=p.read_text()
old="""        'body.impe-sdk-result-in-modal [data-analyst-call-result] {',
        '  display:none!important;',
        '}',"""
new="""        'body.impe-sdk-result-in-modal [data-analyst-call-result] {' +
          'display:none!important;}',"""
assert s.count(old)==1
s=s.replace(old,new)
s=s.replace("    const contextKey = id =>",
            "    // HOSTINGER_AFIPSA_PERSONALIZADO_CONSERVAR\n    const contextKey = id =>",1)
p.write_text(s)
PY
original="$(sha256sum "$IMPE_TOAST_PUBLIC/$TARGET" | cut -d' ' -f1)"
python3 tools/desplegar_sin_toast_analista_hostinger.py --check |
 grep -Fq 'OK: aviso inferior del analista compatible. PRODUCCIÓN INTACTA.'
[[ "$(sha256sum "$IMPE_TOAST_PUBLIC/$TARGET" | cut -d' ' -f1)" == "$original" ]]
python3 tools/desplegar_sin_toast_analista_hostinger.py --apply |
 grep -Fq 'OK: aviso inferior eliminado. Respaldo:'
node --check "$IMPE_TOAST_PUBLIC/$TARGET"
grep -Fq "body [data-analyst-call-result]" "$IMPE_TOAST_PUBLIC/$TARGET"
grep -Fq "root.sessionStorage.removeItem(legacyKey);" "$IMPE_TOAST_PUBLIC/$TARGET"
grep -Fq "HOSTINGER_AFIPSA_PERSONALIZADO_CONSERVAR" "$IMPE_TOAST_PUBLIC/$TARGET"
! grep -Fq "body.impe-sdk-result-in-modal [data-analyst-call-result]" "$IMPE_TOAST_PUBLIC/$TARGET"
test -n "$(find "$TMP/backups" -type f)"
python3 tools/desplegar_sin_toast_analista_hostinger.py --check |
 grep -Fq 'ya desactivado'

# Otro archivo modificado fuera de los anclajes es conservado íntegramente.
export IMPE_TOAST_PUBLIC="$TMP/incompatible"
mkdir -p "$IMPE_TOAST_PUBLIC/public/javascript"
touch "$IMPE_TOAST_PUBLIC/index.php"
git show "9cfa02e249127b1fcf13dbd4c11d73ebb059cd75:$TARGET" > "$IMPE_TOAST_PUBLIC/$TARGET"
sed -i 's/root.document.head.appendChild(finishedCss);/root.document.head.appendChild(otherStyles);/' "$IMPE_TOAST_PUBLIC/$TARGET"
checksum="$(sha256sum "$IMPE_TOAST_PUBLIC/$TARGET" | cut -d' ' -f1)"
if python3 tools/desplegar_sin_toast_analista_hostinger.py --apply > "$TMP/conflict.log" 2>&1; then
 echo "ERROR: se sobrescribió un observador incompatible" >&2; exit 1
fi
grep -Fq 'CONFLICTO:' "$TMP/conflict.log"
[[ "$(sha256sum "$IMPE_TOAST_PUBLIC/$TARGET" | cut -d' ' -f1)" == "$checksum" ]]

# Ninguna de las dos variantes toca el motor o shell.
node tests/telefonia_analista_resultado_transferido_smoke.js
echo "Analista toast: OK (login sin aviso, AFIPSA intacta, despliegue seguro)."
