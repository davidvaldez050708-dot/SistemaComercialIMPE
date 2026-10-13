#!/usr/bin/env bash
# Ajuste visual exclusivamente de Llamadas recibidas (Analistas).
# No reemplaza PHP compartido ni toca telefonía o MySQL.
# --check: preflight sin escritura. --apply: respaldo, parche, CSS atómico.
set -euo pipefail
umask 077

MODE="${1:---check}"
[[ "$MODE" == "--check" || "$MODE" == "--apply" ]] || {
    echo "Uso: bash tools/desplegar_ui_entrantes_analista_hostinger.sh --check|--apply" >&2
    exit 2
}
SRC="${IMPE_UI_ENTRANTES_SOURCE:-$HOME/crm-preparacion}"
LIVE="${IMPE_UI_ENTRANTES_PUBLIC:-$HOME/domains/fundacionrededumex.org/public_html}"
BACKUPS="${IMPE_UI_ENTRANTES_BACKUPS:-$HOME/respaldos-crm}"
BASE="6ab81c43dfc814ebcf58e3c4b4cf1c46d5713a3c"
TARGET="d6e08d520ffd5c167b4fde176202f3892151dd34"
CSS="public/css/seguimiento_entrantes_analista.css"
FILES=(
    app/views/layout/dashboard_head.php
    app/views/seguimiento_vinculacion/index.php
    app/views/seguimiento_vinculacion/estado.php
    app/views/seguimiento_vinculacion/llamadas_entrantes.php
)

[[ -d "$SRC/.git" && -d "$LIVE" ]] || {
    echo "ERROR: faltan el repositorio de preparación o el directorio publicado." >&2
    exit 1
}
for commit in "$BASE" "$TARGET"; do
    git -C "$SRC" cat-file -e "$commit^{commit}" || {
        echo "ERROR: no existe el commit $commit. Actualiza crm-preparacion." >&2
        exit 1
    }
done

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
git -C "$SRC" diff --binary "$BASE" "$TARGET" -- "${FILES[@]}" > "$TMP/cambios.patch"
[[ -s "$TMP/cambios.patch" ]] || {
    echo "ERROR: el parche visual está vacío." >&2
    exit 1
}
git -C "$SRC" show "$TARGET:$CSS" > "$TMP/nuevo.css"
[[ -s "$TMP/nuevo.css" ]] || {
    echo "ERROR: no pudo prepararse el CSS." >&2
    exit 1
}
grep -Fq '.linkage-incoming-entry' "$TMP/nuevo.css" &&
grep -Fq '.linkage-incoming-empty-panel' "$TMP/nuevo.css" || {
    echo "ERROR: el CSS no contiene los selectores esperados." >&2
    exit 1
}
for file in "${FILES[@]}"; do
    git -C "$SRC" show "$TARGET:$file" > "$TMP/$(basename "$file").php"
    php -l "$TMP/$(basename "$file").php" >/dev/null
    [[ -f "$LIVE/$file" && ! -L "$LIVE/$file" ]] || {
        echo "ERROR: falta o está enlazado $file en producción." >&2
        exit 1
    }
done
if [[ -L "$LIVE/$CSS" ]]; then
    echo "ERROR: el CSS de producción es un enlace simbólico." >&2
    exit 1
fi

PATCH_NEEDED=1
if (cd "$LIVE" && git apply --reverse --check "$TMP/cambios.patch" >/dev/null 2>&1); then
    PATCH_NEEDED=0
elif ! (cd "$LIVE" && git apply --check "$TMP/cambios.patch" >/dev/null 2>&1); then
    echo "CONFLICTO: las vistas vivas tienen diferencias que necesitan revisión." >&2
    echo "No se modificó nada en Hostinger. Comparte la salida." >&2
    exit 1
fi
CSS_NEEDED=1
if [[ -f "$LIVE/$CSS" ]] && cmp -s "$TMP/nuevo.css" "$LIVE/$CSS"; then
    CSS_NEEDED=0
fi

echo "Verificación: vistas=$(if [[ $PATCH_NEEDED == 1 ]]; then echo 'por actualizar'; else echo 'actualizadas'; fi), CSS=$(if [[ $CSS_NEEDED == 1 ]]; then echo 'por publicar'; else echo 'publicado'; fi)."
if [[ $PATCH_NEEDED == 0 && $CSS_NEEDED == 0 ]]; then
    echo "OK: todo el diseño ya está publicado."
    exit 0
fi
if [[ "$MODE" == "--check" ]]; then
    echo "OK: comprobación de solo lectura. Ejecuta --apply para publicar."
    exit 0
fi

mkdir -p "$BACKUPS"
BACKUP="$BACKUPS/ui-entrantes-analista-$(date +%Y%m%d-%H%M%S)-$$"
mkdir -p "$BACKUP"
for file in "${FILES[@]}"; do
    mkdir -p "$BACKUP/$(dirname "$file")"
    cp -p "$LIVE/$file" "$BACKUP/$file"
done
HAD_CSS=0
if [[ -f "$LIVE/$CSS" ]]; then
    HAD_CSS=1
    mkdir -p "$BACKUP/$(dirname "$CSS")"
    cp -p "$LIVE/$CSS" "$BACKUP/$CSS"
fi
rollback() {
    code=$?
    if [[ $code -ne 0 ]]; then
        for file in "${FILES[@]}"; do cp -p "$BACKUP/$file" "$LIVE/$file"; done
        if [[ $HAD_CSS == 1 ]]; then cp -p "$BACKUP/$CSS" "$LIVE/$CSS"; else rm -f "$LIVE/$CSS"; fi
        echo "ERROR: se restauró el respaldo $BACKUP" >&2
    fi
    exit "$code"
}
trap rollback EXIT

if [[ $PATCH_NEEDED == 1 ]]; then
    (cd "$LIVE" && git apply "$TMP/cambios.patch")
fi
if [[ $CSS_NEEDED == 1 ]]; then
    mkdir -p "$(dirname "$LIVE/$CSS")"
    install -m 0644 "$TMP/nuevo.css" "$LIVE/$CSS"
fi
for file in "${FILES[@]}"; do php -l "$LIVE/$file" >/dev/null; done
cmp -s "$TMP/nuevo.css" "$LIVE/$CSS" || {
    echo "ERROR: el CSS publicado no coincide con GitHub." >&2
    exit 1
}
(cd "$LIVE" && git apply --reverse --check "$TMP/cambios.patch")
trap - EXIT
echo "OK: interfaz Llamadas recibidas actualizada en Hostinger."
echo "Respaldo: $BACKUP"
echo "Archivos telefónicos, MySQL y Zadarma: sin modificaciones."
