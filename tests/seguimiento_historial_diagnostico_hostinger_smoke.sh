#!/usr/bin/env bash
# Comprueba preflight, rollback potencial y escritura del error PHP fuera de public_html.
set -euo pipefail
cd "$(dirname "$0")/.."
ROOT="$PWD"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
LIVE="$TMP/domain/public_html"
FILE="prueba_telefonia/api/llamadas_seguimiento.php"
LOG_DIR="$TMP/domain/crm-diagnosticos"
mkdir -p "$LIVE/$(dirname "$FILE")" "$TMP/backups"
git show "7f56c53d12ff35edbb9072d6ee97960b0fea88b5:$FILE" > "$LIVE/$FILE"
export IMPE_DIAGNOSTICO_SOURCE="$ROOT"
export IMPE_DIAGNOSTICO_PUBLIC="$LIVE"
export IMPE_DIAGNOSTICO_BACKUPS="$TMP/backups"

bash tools/desplegar_diagnostico_historial_llamadas_hostinger.sh --check | grep -q "PRODUCCIÓN INTACTA"
[[ ! -d "$LOG_DIR" ]]
bash tools/desplegar_diagnostico_historial_llamadas_hostinger.sh --apply | grep -q "diagnóstico habilitado"
git show "5bab25f9ef274c10b08929d980bbfa9c09172b04:$FILE" | cmp - "$LIVE/$FILE"
[[ -d "$LOG_DIR" && "$(stat -c '%a' "$LOG_DIR")" == "700" ]]
[[ -n "$(find "$TMP/backups" -type f)" ]]

# Forzar falta de dependencia exclusivamente en el sandbox de la prueba.
# La excepción debe producir un JSON seguro y una línea de bitácora privada.
php -d display_errors=0 "$LIVE/$FILE" > "$TMP/response.json" 2> "$TMP/php-stderr" || true
php -r '
    $json = json_decode(file_get_contents($argv[1]), true);
    if (!is_array($json) || ($json["ok"] ?? null) !== false ||
        !str_contains((string)($json["mensaje"] ?? ""), "Referencia: ")) exit(1);
' "$TMP/response.json"
grep -Fq '[llamadas_seguimiento:' "$LOG_DIR/llamadas_seguimiento.log"
[[ "$(stat -c '%a' "$LOG_DIR/llamadas_seguimiento.log")" == "600" ]]
bash tools/desplegar_diagnostico_historial_llamadas_hostinger.sh --apply | grep -q "ya actualizado"
echo "Diagnóstico de historial: OK (JSON, log privado, respaldo, idempotencia)"
