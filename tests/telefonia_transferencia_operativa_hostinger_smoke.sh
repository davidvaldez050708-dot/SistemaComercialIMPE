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

# Hostinger puede tener lógica propia en las dos anclas antiguas; se debe
# fusionar sin borrar ni normalizar esas personalizaciones.
git show "$BASE:public/javascript/telefonia_shell.js" > "$TMP/hostinger-custom.js"
python3 - "$TMP/hostinger-custom.js" <<'PY'
import pathlib,sys
p=pathlib.Path(sys.argv[1])
s=p.read_text()
old="if (!cfg.analystIncomingResults || !hostFrame ||"
new="if (!cfg.analystIncomingResults || !hostFrame ||\n                !cfg.canTransfer ||"
assert s.count(old)==1
s=s.replace(old,new,1)
old2="(ongoing && state?.direction !== 'incoming')"
new2="(ongoing && state?.direction !== 'incoming' && Boolean(state?.active))"
assert s.count(old2)==1
s=s.replace(old2,new2,1)
p.write_text(s)
PY
python3 tools/fusionar_telefonia_transferencia_operativa_hostinger.py "$TMP/hostinger-custom.js" shell |
  grep -Fq 'FUSIÓN COMPATIBLE'
node --check "$TMP/hostinger-custom.js"
grep -Fq '!cfg.canTransfer ||' "$TMP/hostinger-custom.js"
grep -Fq "(ongoing && state?.direction !== 'incoming' && Boolean(state?.active))" "$TMP/hostinger-custom.js"
grep -Fq 'VISIBILIDAD_RECEPTOR_TRANSFER_20261015' "$TMP/hostinger-custom.js"
grep -Fq 'SOPORTE_RECEPTOR_TRANSFER_20261015' "$TMP/hostinger-custom.js"
cp "$TMP/hostinger-custom.js" "$TMP/custom-updated.js"
python3 tools/fusionar_telefonia_transferencia_operativa_hostinger.py "$TMP/hostinger-custom.js" shell |
  grep -Fq 'YA ACTUALIZADO'
cmp -s "$TMP/hostinger-custom.js" "$TMP/custom-updated.js"

# Reproducción de los dos fragmentos aportados por el usuario: la versión
# de Hostinger NO incluye showProviderControls y no tiene guarda nativa en
# customVisible. El código del modal finalizado debe conservarse intacto.
git show "$BASE:public/javascript/telefonia_shell.js" > "$TMP/hostinger-sin-metodo.js"
python3 - "$TMP/hostinger-sin-metodo.js" <<'PY'
import pathlib, re, sys
p=pathlib.Path(sys.argv[1])
s=p.read_text()
start=s.index('        showProviderControls: function () {')
end=s.index('        openDialer:', start)
assert s[start:end].rstrip().endswith('},')
s=s[:start]+s[end:]
pattern=(r'const nativeVisible = [\s\S]{10,700}?;'
         r'(?=\s*\n\s*const pending = pendingFor\(state\);)')
replace="""const nativeVisible = Boolean(state?.hostReady) && ongoing &&
            state?.direction !== 'incoming' && forceNativeControls && !minimized;"""
s,count=re.subn(pattern,replace,s,count=1)
assert count==1
guard="""            !(cfg.analystIncomingResults && nativeVisible) &&
            !minimized"""
assert s.count(guard)==1
s=s.replace(guard,"            !minimized",1)
assert s.count("showProviderControls: function () {")==0
assert s.count("const customVisible")==1
p.write_text(s)
PY
before="$(sha256sum "$TMP/hostinger-sin-metodo.js" | cut -d' ' -f1)"
python3 tools/fusionar_telefonia_transferencia_operativa_hostinger.py \
 "$TMP/hostinger-sin-metodo.js" shell | grep -Fq 'FUSIÓN COMPATIBLE'
node --check "$TMP/hostinger-sin-metodo.js"
grep -Fq 'showProviderControls: function () {' "$TMP/hostinger-sin-metodo.js"
grep -Fq 'nativeTransferOpen = true;' "$TMP/hostinger-sin-metodo.js"
grep -Fq 'nativeTransferOpen = false;' "$TMP/hostinger-sin-metodo.js"
grep -Fq '!nativeVisible && !minimized' "$TMP/hostinger-sin-metodo.js"
grep -Fq "finishedIncoming" "$TMP/hostinger-sin-metodo.js"
cp "$TMP/hostinger-sin-metodo.js" "$TMP/hostinger-actualizado.js"
python3 tools/fusionar_telefonia_transferencia_operativa_hostinger.py \
 "$TMP/hostinger-sin-metodo.js" shell | grep -Fq 'YA ACTUALIZADO'
cmp -s "$TMP/hostinger-sin-metodo.js" "$TMP/hostinger-actualizado.js"
test "$before" != "$(sha256sum "$TMP/hostinger-sin-metodo.js" | cut -d' ' -f1)"

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
sed -i "s/forceNativeControls = true;/forceNativeControls = false;/" "$IMPE_OPERATIVE_PUBLIC/public/javascript/telefonia_shell.js"
sha="$(sha256sum "$IMPE_OPERATIVE_PUBLIC/public/javascript/telefonia_shell.js" | cut -d' ' -f1)"
if bash tools/desplegar_transferencia_operativa_hostinger.sh --apply > "$TMP/conflict.log" 2>&1; then
    echo "ERROR: publicación aceptó personalización incompatible" >&2; exit 1
fi
grep -Fq 'CONFLICTO:' "$TMP/conflict.log"
[[ "$(sha256sum "$IMPE_OPERATIVE_PUBLIC/public/javascript/telefonia_shell.js" | cut -d' ' -f1)" == "$sha" ]]
echo 'Transferencia operativa: OK (recepción por extensión, host, shell y deploy reversible).'
