#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
BASE="03c0028e9bb5b62c91f1ebba0266fae4eca521f4"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
export IMPE_OPERATIVE_SOURCE="$PWD"
export IMPE_OPERATIVE_BACKUPS="$TMP/respaldos"
export IMPE_OPERATIVE_PUBLIC="$TMP/live"
mkdir -p "$IMPE_OPERATIVE_PUBLIC/public/javascript" "$IMPE_OPERATIVE_PUBLIC/app/views/telefonia"
touch "$IMPE_OPERATIVE_PUBLIC/index.php"
for file in app/views/telefonia/shell.php public/javascript/telefonia_shell.js \
            public/javascript/telefonia_enrutamiento.js public/javascript/telefonia_host.js; do
    git show "$BASE:$file" > "$IMPE_OPERATIVE_PUBLIC/$file"
done
# Variantes históricas del shell (con y sin la guarda nativeVisible)
git show 3783f69f6:public/javascript/telefonia_shell.js > "$TMP/historico.js"
python3 tools/fusionar_telefonia_transferencia_operativa_hostinger.py "$TMP/historico.js" shell |
  grep -Fq 'FUSIÓN COMPATIBLE'
node --check "$TMP/historico.js"
python3 tools/fusionar_telefonia_transferencia_operativa_hostinger.py "$TMP/historico.js" shell |
  grep -Fq 'YA ACTUALIZADO'
# La última publicación histórica sí tiene resumen de entrantes, pero no
# necesariamente la guarda analystIncomingResults && nativeVisible.
git show "$BASE:public/javascript/telefonia_shell.js" > "$TMP/sin-guarda.js"
python3 - "$TMP/sin-guarda.js" <<'PY'
import pathlib,sys
p=pathlib.Path(sys.argv[1])
s=p.read_text()
old="""            !(cfg.analystIncomingResults && nativeVisible) &&
            !minimized"""
assert s.count(old)==1
p.write_text(s.replace(old,"            !minimized",1))
PY
python3 tools/fusionar_telefonia_transferencia_operativa_hostinger.py "$TMP/sin-guarda.js" shell |
  grep -Fq 'FUSIÓN COMPATIBLE'
node --check "$TMP/sin-guarda.js"

# Ensayar check, publicación, idempotencia y respaldo sin producción real.
bash -n tools/desplegar_transferencia_operativa_hostinger.sh
python3 -m py_compile tools/fusionar_telefonia_transferencia_operativa_hostinger.py
bash tools/desplegar_transferencia_operativa_hostinger.sh --check |
  grep -Fq 'PRODUCCIÓN INTACTA'
! grep -Fq "'alwaysReceive' =>" "$IMPE_OPERATIVE_PUBLIC/app/views/telefonia/shell.php"
bash tools/desplegar_transferencia_operativa_hostinger.sh --apply |
  grep -Fq 'transferencia asistida publicada'
grep -Fq "'alwaysReceive' =>" "$IMPE_OPERATIVE_PUBLIC/app/views/telefonia/shell.php"
grep -Fq "Abrir teclado Zadarma" "$IMPE_OPERATIVE_PUBLIC/public/javascript/telefonia_enrutamiento.js"
grep -Fq "await sendTransferDtmf(" "$IMPE_OPERATIVE_PUBLIC/public/javascript/telefonia_host.js"
[[ -n "$(find "$TMP/respaldos" -type f)" ]]
bash tools/desplegar_transferencia_operativa_hostinger.sh --check |
  grep -Fq 'ya publicada'

# Con modificaciones irreconciliables, jamás escribir en el sitio.
export IMPE_OPERATIVE_PUBLIC="$TMP/conflict"
mkdir -p "$IMPE_OPERATIVE_PUBLIC/public/javascript" "$IMPE_OPERATIVE_PUBLIC/app/views/telefonia"
touch "$IMPE_OPERATIVE_PUBLIC/index.php"
for file in app/views/telefonia/shell.php public/javascript/telefonia_shell.js \
            public/javascript/telefonia_enrutamiento.js public/javascript/telefonia_host.js; do
    git show "$BASE:$file" > "$IMPE_OPERATIVE_PUBLIC/$file"
done
sed -i "s/if (!cfg.analystIncomingResults || !hostFrame ||/if (true || !hostFrame ||/" "$IMPE_OPERATIVE_PUBLIC/public/javascript/telefonia_shell.js"
sha="$(sha256sum "$IMPE_OPERATIVE_PUBLIC/public/javascript/telefonia_shell.js" | cut -d' ' -f1)"
if bash tools/desplegar_transferencia_operativa_hostinger.sh --apply > "$TMP/conflict.log" 2>&1; then
    echo "ERROR: publicación aceptó personalización incompatible" >&2; exit 1
fi
grep -Fq 'CONFLICTO:' "$TMP/conflict.log"
[[ "$(sha256sum "$IMPE_OPERATIVE_PUBLIC/public/javascript/telefonia_shell.js" | cut -d' ' -f1)" == "$sha" ]]
echo 'Transferencia operativa: OK (recepción por extensión, host, shell y deploy reversible).'
