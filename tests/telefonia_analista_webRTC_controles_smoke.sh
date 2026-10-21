#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
BASE="1d130d13b7c3a12d7affe5d4d5d6a2fcc5a7d7de"
PREVIOUS="13a8ed2a48a615620cf2480829fb474898bdd4be"
TARGET="35414e88bf88e2752d5bd821e0242edd9d644e48"
php -l app/views/layout/topbar.php >/dev/null
php -l app/views/telefonia/shell.php >/dev/null
node --check public/javascript/telefonia_shell.js
node --check public/javascript/telefonia_persistente.js

# La compatibilidad de Marketing se mantiene: sólo el Analista puede
# abrir el widget por falta de evento NOTIFY_INTERNAL.
grep -Fq "data-telephony-analyst=" app/views/layout/topbar.php
grep -Fq "receptionButton?.dataset.telephonyAnalyst === '1'" public/javascript/telefonia_persistente.js
grep -Fq "cfg.analystIncomingResults &&" public/javascript/telefonia_shell.js
grep -Fq "isPhoneReady: function ()" public/javascript/telefonia_shell.js
grep -Fq "shell.isPhoneReady() === true" public/javascript/telefonia_persistente.js
grep -Fq "Nunca interpretar la invocación SDK como confirmación de conexión." public/javascript/telefonia_shell.js
grep -Fq "if (abrirAlConectar) abrirControlesAnalista();" public/javascript/telefonia_persistente.js
grep -Fq "activarRecepcion(esAnalista);" public/javascript/telefonia_persistente.js
grep -Fq ".impe-phone-call.is-analyst-native .impe-phone-call-host" app/views/telefonia/shell.php

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
mkdir -p "$TMP/site" "$TMP/backups"
git archive "$BASE" | tar -xf - -C "$TMP/site"
export IMPE_WEBRTC_SOURCE="$PWD"
export IMPE_WEBRTC_PUBLIC="$TMP/site"
export IMPE_WEBRTC_BACKUPS="$TMP/backups"

# La comprobación no modifica archivos.
cp "$TMP/site/public/javascript/telefonia_shell.js" "$TMP/original.js"
bash tools/desplegar_controles_webRTC_analista_hostinger.sh --check
cmp -s "$TMP/original.js" "$TMP/site/public/javascript/telefonia_shell.js"
bash tools/desplegar_controles_webRTC_analista_hostinger.sh --apply
for f in app/views/layout/topbar.php app/views/telefonia/shell.php \
    public/javascript/telefonia_shell.js public/javascript/telefonia_persistente.js; do
  git show "$TARGET:$f" | cmp -s - "$TMP/site/$f"
done
bash tools/desplegar_controles_webRTC_analista_hostinger.sh --check

# Simular al usuario que ya publicó la versión anterior del auricular.
for f in app/views/layout/topbar.php app/views/telefonia/shell.php \
    public/javascript/telefonia_shell.js public/javascript/telefonia_persistente.js; do
  git show "$PREVIOUS:$f" > "$TMP/site/$f"
done
bash tools/desplegar_controles_webRTC_analista_hostinger.sh --check
bash tools/desplegar_controles_webRTC_analista_hostinger.sh --apply
for f in app/views/layout/topbar.php app/views/telefonia/shell.php \
    public/javascript/telefonia_shell.js public/javascript/telefonia_persistente.js; do
  git show "$TARGET:$f" | cmp -s - "$TMP/site/$f"
done
bash tools/desplegar_controles_webRTC_analista_hostinger.sh --check

# Conflicto posterior: rechazo sin sobrescribir personalizaciones.
# Alterar un bloque realmente incluido en el parche incremental:
# personalizaciones ajenas al parche pueden permanecer intactas.
sed -i 's/if (abrirAlConectar) abrirControlesAnalista();/if (abrirAlConectar) abrirOtroVisor();/' \
    "$TMP/site/public/javascript/telefonia_persistente.js"
if bash tools/desplegar_controles_webRTC_analista_hostinger.sh --check >"$TMP/error.log" 2>&1; then
  echo "ERROR: despliegue ignoró cambios no compatibles." >&2
  exit 1
fi
echo "WebRTC Analista: OK (sintaxis, aislamiento, despliegue, idempotencia y conflictos)."
