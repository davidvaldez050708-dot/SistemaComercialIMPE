#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
BASE="97945c31785a44e7d2fa78e4f7357fa17bc8f9db"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
export IMPE_CONTEXT_SOURCE="$PWD"
export IMPE_CONTEXT_BACKUPS="$TMP/backup"
export IMPE_CONTEXT_PUBLIC="$TMP/live"
mkdir -p "$IMPE_CONTEXT_PUBLIC/app/controllers" "$IMPE_CONTEXT_PUBLIC/public/javascript"
touch "$IMPE_CONTEXT_PUBLIC/index.php"
for f in app/controllers/TelefoniaController.php \
    public/javascript/telefonia_enrutamiento.js \
    public/javascript/telefonia_analista_historial_pasivo.js \
    public/javascript/seguimiento_entrantes_analista.js; do
  git show "$BASE:$f" > "$IMPE_CONTEXT_PUBLIC/$f"
done
echo '// Motor SIP no alterado' > "$IMPE_CONTEXT_PUBLIC/public/javascript/telefonia_host.js"
sha="$(sha256sum "$IMPE_CONTEXT_PUBLIC/public/javascript/telefonia_host.js" | cut -d' ' -f1)"
php -l app/services/TelefoniaTransferenciaContextoService.php >/dev/null
php -l app/controllers/TelefoniaController.php >/dev/null
node --check public/javascript/telefonia_enrutamiento.js
node --check public/javascript/telefonia_analista_historial_pasivo.js
node --check public/javascript/seguimiento_entrantes_analista.js
node tests/telefonia_analista_resultado_transferido_smoke.js
php -r '
$s=file_get_contents("app/services/TelefoniaTransferenciaContextoService.php");
foreach(["pbx_call_id = ? AND internal = ?","AND analista_id = ? AND estado_id = ?",
  "d.destino_usuario = ? AND d.destino_extension = ?", "AND d.referencia = ?",
  "count(" . chr(36) . "rows) !== 1"] as $guard){
    if(strpos($s,$guard)===false)exit(1);
}
echo "Contexto institucional: controles presentes.\n";
'
bash -n tools/desplegar_contexto_transferencia_hostinger.sh
bash tools/desplegar_contexto_transferencia_hostinger.sh --check | grep -Fq 'PRODUCCIÓN INTACTA'
! test -e "$IMPE_CONTEXT_PUBLIC/app/services/TelefoniaTransferenciaContextoService.php"
bash tools/desplegar_contexto_transferencia_hostinger.sh --apply | grep -Fq 'contexto de transferencia publicado'
test -s "$IMPE_CONTEXT_PUBLIC/app/services/TelefoniaTransferenciaContextoService.php"
[[ "$(sha256sum "$IMPE_CONTEXT_PUBLIC/public/javascript/telefonia_host.js" | cut -d' ' -f1)" == "$sha" ]]
test -n "$(find "$TMP/backup" -type f)"
bash tools/desplegar_contexto_transferencia_hostinger.sh --check | grep -Fq 'ya publicadas'
export IMPE_CONTEXT_PUBLIC="$TMP/conflict"
mkdir -p "$IMPE_CONTEXT_PUBLIC/app/controllers" "$IMPE_CONTEXT_PUBLIC/public/javascript"
touch "$IMPE_CONTEXT_PUBLIC/index.php"
for f in app/controllers/TelefoniaController.php \
    public/javascript/telefonia_enrutamiento.js \
    public/javascript/telefonia_analista_historial_pasivo.js \
    public/javascript/seguimiento_entrantes_analista.js; do
  git show "$BASE:$f" > "$IMPE_CONTEXT_PUBLIC/$f"
done
sed -i 's/private function validarTransferenciaPost(): void/private function validarTransferenciaPost($param): void/' "$IMPE_CONTEXT_PUBLIC/app/controllers/TelefoniaController.php"
before="$(sha256sum "$IMPE_CONTEXT_PUBLIC/app/controllers/TelefoniaController.php" | cut -d' ' -f1)"
if bash tools/desplegar_contexto_transferencia_hostinger.sh --apply > "$TMP/conflict.log" 2>&1; then
    echo "ERROR: no se detectó archivo personalizado incompatible"; exit 1
fi
grep -Fq 'CONFLICTO:' "$TMP/conflict.log"
[[ "$(sha256sum "$IMPE_CONTEXT_PUBLIC/app/controllers/TelefoniaController.php" | cut -d' ' -f1)" == "$before" ]]
echo 'Identificación institucional: OK (PBX, expediente propio, sin revelar otras carteras, deploy reversible).'
