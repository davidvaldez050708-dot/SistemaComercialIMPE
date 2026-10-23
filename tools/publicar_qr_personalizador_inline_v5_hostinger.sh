#!/usr/bin/env bash
# Publica solo el editor QR V5 (JS y CSS). No cambia PHP ni SQL.
set -Eeuo pipefail
umask 077

MODE="${1:---check}"
[[ "$MODE" == "--check" || "$MODE" == "--apply" ]] || { echo "Uso: bash publicar_qr_personalizador_inline_v5_hostinger.sh --check|--apply" >&2; exit 2; }

SITE="${IMPE_CONVOCATORIAS_SITE:-$HOME/domains/fundacionrededumex.org/public_html}"
WORK="$HOME/entregas-impe/convocatorias-qr-inline-v5"
BACKUPS="$HOME/respaldos-impe-qr-inline-v5"
REV="4d2e4701820eb52cb8d1e56306b15b4582d5111a"
RAW="https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/$REV"
JS="public/javascript/convocatorias_gestion_v5.js"
CSS="public/css/convocatorias_gestion_v5.css"
OLD_JS="d6a5d210da10cce7d1b7484783901fe0f0b6d373"
OLD_CSS="3197c939e28d71969b2145188d6983072227f4ee"
NEW_JS="69b219e389ca89d9bf2177676b827e73976cd0cd"
NEW_CSS="b4aa71784d9a01b1fc5c5436e4a2b01d37d412bb"

for cmd in git curl php mktemp cp mv; do
    command -v "$cmd" >/dev/null || { echo "Falta $cmd. SITIO INTACTO." >&2; exit 1; }
done
[[ -f "$SITE/index.php" && -f "$SITE/$JS" && -f "$SITE/$CSS" ]] || {
    echo "Faltan archivos en Hostinger. SITIO INTACTO." >&2; exit 1;
}
mkdir -p -m 700 "$WORK"
STAGE="$(mktemp -d "$WORK/revision-XXXXXX")"
trap 'rm -rf "$STAGE"' EXIT

echo "1. Descargando 2 archivos desde GitHub..."
for file in "$JS" "$CSS"; do
    mkdir -p "$STAGE/$(dirname "$file")"
    curl -fLsS --retry 2 "$RAW/$file" -o "$STAGE/$file"
done
[[ "$(git hash-object "$STAGE/$JS")" == "$NEW_JS" && "$(git hash-object "$STAGE/$CSS")" == "$NEW_CSS" ]] || {
    echo "Descarga distinta del paquete autorizado. SITIO INTACTO." >&2; exit 1;
}
grep -Fq "IMPE_QR_INLINE_V5_20261023" "$STAGE/$JS"
grep -Fq "IMPE_QR_INLINE_V5_20261023" "$STAGE/$CSS"
if command -v node >/dev/null 2>&1; then node --check "$STAGE/$JS"; fi
php -l "$SITE/app/views/convocatorias/index.php" >/dev/null

echo "2. Verificando personalizaciones de producción..."
actual_js="$(git hash-object "$SITE/$JS")"
actual_css="$(git hash-object "$SITE/$CSS")"
for tipo in js css; do
    if [[ "$tipo" == js ]]; then
        archivo="$JS"; actual="$actual_js"; base="$OLD_JS"; nuevo="$NEW_JS"
    else
        archivo="$CSS"; actual="$actual_css"; base="$OLD_CSS"; nuevo="$NEW_CSS"
    fi
    if [[ "$actual" == "$nuevo" ]]; then
        echo "SIN CAMBIOS: $archivo"
    elif [[ "$actual" == "$base" ]]; then
        echo "PREPARADO: $archivo"
    else
        echo "CONFLICTO: $archivo no coincide con la versión revisada. SITIO INTACTO." >&2
        exit 1
    fi
done
if [[ "$MODE" == "--check" ]]; then
    echo "REVISIÓN COMPATIBLE: 2 archivos, sin PHP ni SQL."
    echo "SITIO INTACTO"
    exit 0
fi
if [[ "$actual_js" == "$NEW_JS" && "$actual_css" == "$NEW_CSS" ]]; then
    echo "ENTREGA YA PUBLICADA"
    exit 0
fi

echo "3. Respaldo de los dos archivos originales..."
mkdir -p -m 700 "$BACKUPS"
BACKUP="$(mktemp -d "$BACKUPS/qr-v5-$(date +%Y%m%d-%H%M%S)-XXXXXX")"
for file in "$JS" "$CSS"; do
    mkdir -p "$BACKUP/$(dirname "$file")"
    cp -p "$SITE/$file" "$BACKUP/$file"
done
[[ "$(git hash-object "$BACKUP/$JS")" == "$actual_js" && "$(git hash-object "$BACKUP/$CSS")" == "$actual_css" ]] || {
    echo "Respaldo incompleto. SITIO INTACTO." >&2; exit 1;
}

TOCADOS=()
restaurar() {
    estado=$?
    trap - ERR
    if (( ${#TOCADOS[@]} > 0 )); then
        echo "ERROR: restaurando activos publicados..." >&2
        for file in "${TOCADOS[@]}"; do
            cp -p "$BACKUP/$file" "$SITE/$file" || echo "Restauración manual requerida: $BACKUP/$file" >&2
        done
    fi
    exit "$estado"
}
trap restaurar ERR
[[ "$(git hash-object "$SITE/$JS")" == "$actual_js" && "$(git hash-object "$SITE/$CSS")" == "$actual_css" ]]

echo "4. Publicando JS y CSS de forma selectiva..."
for file in "$JS" "$CSS"; do
    if [[ "$(git hash-object "$SITE/$file")" == "$(git hash-object "$STAGE/$file")" ]]; then continue; fi
    tmp="$SITE/$file.qr-v5-$$"
    cp "$STAGE/$file" "$tmp"
    chmod --reference="$SITE/$file" "$tmp"
    TOCADOS+=("$file")
    mv -f "$tmp" "$SITE/$file"
    echo "PUBLICADO: $file"
done
[[ "$(git hash-object "$SITE/$JS")" == "$NEW_JS" && "$(git hash-object "$SITE/$CSS")" == "$NEW_CSS" ]]
trap - ERR
echo "PUBLICACIÓN FINALIZADA. Respaldo: $BACKUP"
echo "Vista mensual, controlador y base de datos intactos."
