#!/usr/bin/env bash
# Prueba aislada sin telefonía, base de datos o producción.
set -euo pipefail
cd "$(dirname "$0")/.."
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
FILE="public/javascript/telefonia_shell.js"
OLD="3783f69f6"
DONOR="d028c0789c6db482d38c2d9e3b6908396c2f9a33"
git show "$OLD:$FILE" > "$TMP/old.js"
git show "$DONOR:$FILE" > "$TMP/donor.js"
cp "$TMP/old.js" "$TMP/stage.js"
# Recrear la misma clase de shell histórico con los tres bloques de
# transferencia agregados sin reemplazar las personalizaciones existentes.
python3 tools/fusionar_shell_transferencias_hostinger.py "$TMP/stage.js" "$TMP/donor.js"
! grep -Fq 'const finishedSdk = Boolean(' "$TMP/stage.js"
grep -Fq 'const workingCall = Boolean(ongoing' "$TMP/stage.js"
cp "$TMP/stage.js" "$TMP/transferencias.js"
node --check "$TMP/stage.js"
python3 tools/fusionar_cierre_transferencia_legacy_hostinger.py "$TMP/stage.js" |
  grep -Fq 'FUSIÓN LEGACY COMPATIBLE'
node --check "$TMP/stage.js"
grep -Fq 'let recentFinishedIncoming = null;' "$TMP/stage.js"
grep -Fq "transferToggle?.setAttribute('aria-expanded', 'false');" "$TMP/stage.js"
grep -Fq "const finishingIncoming = incoming && pending === 'hangup';" "$TMP/stage.js"
grep -Fq 'window.IMPE_TELEPHONY_SHELL = Object.freeze({' "$TMP/stage.js"
cp "$TMP/stage.js" "$TMP/updated.js"
python3 tools/fusionar_cierre_transferencia_legacy_hostinger.py "$TMP/stage.js" |
  grep -Fq 'YA ACTUALIZADO'
cmp -s "$TMP/stage.js" "$TMP/updated.js"

# Personalización ajena fuera de las modificaciones no se pierde.
cp "$TMP/transferencias.js" "$TMP/custom.js"
printf '\n/* NOTA PRIVADA WEBRTC HISTORICA */\n' >> "$TMP/custom.js"
python3 tools/fusionar_cierre_transferencia_legacy_hostinger.py "$TMP/custom.js" |
  grep -Fq 'FUSIÓN LEGACY COMPATIBLE'
grep -Fq 'NOTA PRIVADA WEBRTC HISTORICA' "$TMP/custom.js"

# Variante publicada real (845 líneas): no incluye nativeVisible en la
# condición customVisible. Conservarla sin forzar lógica WebRTC ajena.
cp "$TMP/transferencias.js" "$TMP/no-native-guard.js"
python3 - "$TMP/no-native-guard.js" <<'PY'
import pathlib,sys
p=pathlib.Path(sys.argv[1])
s=p.read_text()
old="""        const customVisible = (incoming || shownOutgoing) &&
            !(cfg.analystIncomingResults && nativeVisible) &&
            !minimized && pending !== 'hangup' && pending !== 'decline';"""
new="""        const customVisible = (incoming || shownOutgoing) &&
            !minimized && pending !== 'hangup' && pending !== 'decline';"""
assert s.count(old)==1
p.write_text(s.replace(old,new,1))
PY
cp "$TMP/no-native-guard.js" "$TMP/legacy-actual-before.js"
python3 tools/fusionar_cierre_transferencia_legacy_hostinger.py "$TMP/no-native-guard.js" |
  grep -Fq 'FUSIÓN LEGACY COMPATIBLE'
node --check "$TMP/no-native-guard.js"
grep -Fq "const finishingIncoming = incoming && pending === 'hangup';" "$TMP/no-native-guard.js"
grep -Fq "transferToggle?.setAttribute('aria-expanded', 'false');" "$TMP/no-native-guard.js"
! grep -Fq "!(cfg.analystIncomingResults && nativeVisible) &&" "$TMP/no-native-guard.js"
cp "$TMP/no-native-guard.js" "$TMP/legacy-actual-after.js"
python3 tools/fusionar_cierre_transferencia_legacy_hostinger.py "$TMP/no-native-guard.js" |
  grep -Fq 'YA ACTUALIZADO'
cmp -s "$TMP/no-native-guard.js" "$TMP/legacy-actual-after.js"

# Si el bloque crítico se modificó, debe fallar y NO escribir el archivo.
cp "$TMP/transferencias.js" "$TMP/conflict.js"
python3 - "$TMP/conflict.js" <<'PY'
import pathlib,sys
p=pathlib.Path(sys.argv[1])
s=p.read_text()
needle="            !minimized && pending !== 'hangup' && pending !== 'decline';"
assert s.count(needle)==1
p.write_text(s.replace(needle,"            !minimized && pending !== 'decline';",1))
PY
cp "$TMP/conflict.js" "$TMP/unchanged.js"
if python3 tools/fusionar_cierre_transferencia_legacy_hostinger.py "$TMP/conflict.js" > "$TMP/out" 2>&1; then
  echo "ERROR: se aceptó variante alterada" >&2; exit 1
fi
grep -Fq 'CONFLICTO:' "$TMP/out"
cmp -s "$TMP/conflict.js" "$TMP/unchanged.js"
echo 'Cierre legacy Hostinger: OK (WebRTC y transferencias preservados; idempotencia, JS y conflicto).'
