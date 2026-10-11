#!/usr/bin/env bash
# Publica solo la paginación del modal Buscar candidato.
# Fusiona cualquier ajuste propio de Hostinger; aborta ANTES de publicar
# si no es posible reconciliar una vista o estilo.
set -euo pipefail
umask 077

SRC="$HOME/crm-preparacion"
DST="$HOME/domains/fundacionrededumex.org/public_html"
VIEW="app/views/seguimiento_vinculacion/estado.php"
FOOTER="app/views/layout/dashboard_footer.php"
CSS="public/css/seguimiento_filtros_layout.css"
JS="public/javascript/seguimiento_candidatos_paginacion.js"

for archivo in "$VIEW" "$FOOTER" "$CSS" "$JS"; do
    if [[ ! -f "$SRC/$archivo" ]]; then
        echo "Falta archivo versionado: $archivo" >&2
        exit 1
    fi
done
for archivo in "$VIEW" "$FOOTER" "$CSS" index.php; do
    if [[ ! -f "$DST/$archivo" ]]; then
        echo "Falta archivo en producción: $archivo" >&2
        exit 1
    fi
done
if [[ -f "$DST/$JS" ]] && ! cmp -s "$DST/$JS" "$SRC/$JS"; then
    echo "Hay otra implementación de paginación en producción. Revisar antes de reemplazar." >&2
    exit 1
fi

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

fusionar() {
    local archivo="$1" base="$2" marcador="$3" sufijo="$4"

    if grep -Fq "$marcador" "$DST/$archivo"; then
        # Idempotente: evita repetir una fusión que ya fue aplicada.
        cp "$DST/$archivo" "$TMP/nuevo-$sufijo"
        return
    fi

    if ! git -C "$SRC" cat-file blob "$base" > "$TMP/base-$sufijo"; then
        echo "No se encuentra la versión base de $archivo en el historial Git." >&2
        exit 1
    fi
    if ! git merge-file -p \
        "$DST/$archivo" "$TMP/base-$sufijo" "$SRC/$archivo" \
        > "$TMP/nuevo-$sufijo"; then
        echo "CONFLICTO en $archivo. No se ha publicado ningún cambio." >&2
        exit 1
    fi
}

echo "1/4 Fusionando ajustes del CRM sin perder cambios de Hostinger..."
fusionar "$VIEW" "1b3e28d02504f5411d4be0be359d64862a50da0e" \
    'const mostrarPaginaLocal = function' view.php
fusionar "$FOOTER" "ff0d85dd788c8c88926798a3dbd992c67d38f0a2" \
    "'seguimiento_candidatos_paginacion.js'" footer.php
fusionar "$CSS" "54ace51e6c58c8511ee63ed4826baae02aa409be" \
    'Buscar candidato · transición de paginación' styles.css

php -l "$TMP/nuevo-view.php"
php -l "$TMP/nuevo-footer.php"
if command -v node >/dev/null 2>&1; then
    node --check "$SRC/$JS"
    (cd "$SRC" && node tests/seguimiento_candidatos_paginacion_smoke.js)
fi
grep -Fq 'const mostrarPaginaLocal = function' "$TMP/nuevo-view.php"
grep -Fq "'seguimiento_candidatos_paginacion.js'" "$TMP/nuevo-footer.php"
grep -Fq 'Buscar candidato · transición de paginación' "$TMP/nuevo-styles.css"

echo "2/4 Guardando respaldo antes de publicar..."
mkdir -p "$HOME/respaldos-crm"
BACKUP="$(mktemp -d "$HOME/respaldos-crm/paginacion-candidatos-$(date +%Y%m%d-%H%M%S)-XXXXXX")"
for archivo in "$VIEW" "$FOOTER" "$CSS"; do
    mkdir -p "$BACKUP/$(dirname "$archivo")"
    cp -p "$DST/$archivo" "$BACKUP/$archivo"
done
if [[ -f "$DST/$JS" ]]; then
    mkdir -p "$BACKUP/$(dirname "$JS")"
    cp -p "$DST/$JS" "$BACKUP/$JS"
fi

echo "3/4 Publicando archivos validados..."
mkdir -p "$DST/$(dirname "$JS")"
cp "$TMP/nuevo-view.php" "$DST/$VIEW"
cp "$TMP/nuevo-footer.php" "$DST/$FOOTER"
cp "$TMP/nuevo-styles.css" "$DST/$CSS"
cp "$SRC/$JS" "$DST/$JS"

echo "4/4 Verificación final..."
php -l "$DST/$VIEW"
php -l "$DST/$FOOTER"
echo "OK: paginación del modal Buscar candidato publicada."
echo "Respaldo: $BACKUP"
