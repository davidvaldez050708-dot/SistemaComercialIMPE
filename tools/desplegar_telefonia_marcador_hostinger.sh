#!/usr/bin/env bash
# Instalación conservadora del marcador visual telefónico.
# No modifica Zadarma, MySQL, roles, API o cron. Siempre prepara TODA la
# fusión antes del primer cp a producción. Abortamos ante conflictos.
set -euo pipefail
umask 077
SRC="${IMPE_DIALER_SOURCE_DIR:-$HOME/crm-preparacion}"
DST="${IMPE_DIALER_PUBLIC_DIR:-$HOME/domains/fundacionrededumex.org/public_html}"
RESP="${IMPE_DIALER_BACKUP_DIR:-$HOME/respaldos-crm}"
FILES=(
    "app/views/telefonia/shell.php"
    "public/javascript/telefonia_shell.js"
    "public/javascript/telefonia_persistente.js"
    "public/javascript/telefonia_recepcion_marketing.js"
    "app/views/layout/dashboard_head.php"
)
BASES=(
    "fc72c621870829fe3f99e80c0e02a39146f56bac"
    "b014e07210a3fd09c2df832ab64bac95520b319e"
    "88e841eef127baf9e902dc6d5b6c3a88ac2d20a7"
    "badf5cd1e83bc15c5599516a5afde8e9c4984c68"
    "ef759c036be91121c29ecec54d206a631c5fa70d"
)
NEW=(
    "public/javascript/telefonia_teclado.js"
    "public/css/telefonia_teclado.css"
)
[[ -d "$SRC/.git" && -f "$DST/index.php" ]] || {
    echo "ERROR: faltan repositorio Git o instalación de producción." >&2; exit 1;
}
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
echo "1/4 Preparando fusión con los cambios existentes de Hostinger..."
for i in "${!FILES[@]}"; do
    file="${FILES[$i]}"
    [[ -f "$SRC/$file" && -f "$DST/$file" ]] || {
        echo "ERROR: falta $file. No se publicó nada." >&2; exit 1;
    }
    mkdir -p "$TMP/$(dirname "$file")"
    if cmp -s "$SRC/$file" "$DST/$file"; then
        cp "$DST/$file" "$TMP/$file"
    else
        git -C "$SRC" cat-file blob "${BASES[$i]}" > "$TMP/base-$i" || {
            echo "ERROR: falta versión base de $file." >&2; exit 1;
        }
        git merge-file -p "$DST/$file" "$TMP/base-$i" "$SRC/$file" > "$TMP/$file" || {
            echo "CONFLICTO: $file. Nada modificado en producción." >&2
            exit 1
        }
    fi
done
for file in "${NEW[@]}"; do
    [[ -f "$SRC/$file" ]] || { echo "ERROR: falta $file en Git." >&2; exit 1; }
    mkdir -p "$TMP/$(dirname "$file")"
    if [[ -f "$DST/$file" ]] && ! cmp -s "$DST/$file" "$SRC/$file"; then
        echo "CONFLICTO: $file ya está personalizado en Hostinger." >&2
        exit 1
    fi
    cp "$SRC/$file" "$TMP/$file"
done
echo "2/4 Comprobando sintaxis e integración..."
php -l "$TMP/app/views/telefonia/shell.php"
php -l "$TMP/app/views/layout/dashboard_head.php"
grep -Fq 'window.IMPE_TELEPHONY_DIALER' "$TMP/public/javascript/telefonia_shell.js"
grep -Fq 'openDialer: openDialer' "$TMP/public/javascript/telefonia_persistente.js"
grep -Fq "telefono.openDialer?.({" "$TMP/public/javascript/telefonia_recepcion_marketing.js"
grep -Fq 'id="impeDialerStyles"' "$TMP/app/views/telefonia/shell.php"
grep -Fq 'telefonia_teclado.js' "$TMP/app/views/layout/dashboard_head.php"
if command -v node >/dev/null 2>&1; then
    for file in public/javascript/telefonia_shell.js \
                public/javascript/telefonia_persistente.js \
                public/javascript/telefonia_recepcion_marketing.js \
                public/javascript/telefonia_teclado.js; do
        node --check "$TMP/$file"
    done
fi
echo "3/4 Respaldando todos los archivos anteriores..."
mkdir -p "$RESP"
BACKUP="$(mktemp -d "$RESP/telefonia-marcador-$(date +%Y%m%d-%H%M%S)-XXXXXX")"
for file in "${FILES[@]}" "${NEW[@]}"; do
    if [[ -f "$DST/$file" ]]; then
        mkdir -p "$BACKUP/$(dirname "$file")"
        cp -p "$DST/$file" "$BACKUP/$file"
    fi
done
echo "4/4 Publicando marcador compacto integrado..."
for file in "${FILES[@]}" "${NEW[@]}"; do
    mkdir -p "$DST/$(dirname "$file")"
    cp "$TMP/$file" "$DST/$file"
done
php -l "$DST/app/views/telefonia/shell.php"
echo "OK: marcador integrado publicado; sin cambios en MySQL ni Zadarma."
echo "Respaldo: $BACKUP"
echo "Cierra sesión y vuelve a entrar para probar Abrir teléfono."
