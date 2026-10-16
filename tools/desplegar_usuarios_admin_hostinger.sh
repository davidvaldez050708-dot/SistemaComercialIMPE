#!/usr/bin/env bash
# Despliega ajustes de Administración de Usuarios a Hostinger.
set -euo pipefail
umask 077

MODE="${1:---check}"

if [[ "$MODE" != "--check" && "$MODE" != "--apply" ]]; then
    echo "Uso: bash tools/desplegar_usuarios_admin_hostinger.sh --check|--apply" >&2
    exit 2
fi

SRC="${IMPE_USUARIOS_SOURCE_DIR:-$PWD}"
if [[ ! -f "$SRC/index.php" ]]; then
    SRC="${IMPE_USUARIOS_SOURCE_DIR:-$HOME/crm-preparacion}"
fi

if [[ -n "${IMPE_USUARIOS_PUBLIC_DIR:-}" ]]; then
    DST="$IMPE_USUARIOS_PUBLIC_DIR"
else
    CANDIDATES=(
        "$HOME/domains/fundacionrededumex.org/public_html"
        "$HOME/domains/mediumturquoise-starling-552985.hostingersite.com/public_html"
        "$HOME/public_html"
    )

    DST=""
    for candidate in "${CANDIDATES[@]}"; do
        if [[ -f "$candidate/index.php" ]]; then
            DST="$candidate"
            break
        fi
    done
fi

if [[ -z "$DST" || ! -f "$DST/index.php" ]]; then
    echo "ERROR: no se encontró la instalación pública." >&2
    exit 1
fi

FILES=(
    "app/views/usuarios/index.php"
    "public/css/dashboard.css"
)

echo "Repositorio fuente : $SRC"
echo "Instalación pública: $DST"
echo

for file in "${FILES[@]}"; do
    if [[ ! -f "$SRC/$file" ]]; then
        echo "ERROR: falta en fuente $file" >&2
        exit 1
    fi
done

if command -v php >/dev/null 2>&1; then
    php -l "$SRC/app/views/usuarios/index.php" >/dev/null
fi

if [[ "$MODE" == "--check" ]]; then
    changes=0

    for file in "${FILES[@]}"; do
        if [[ ! -f "$DST/$file" ]]; then
            echo "NUEVO     $file"
            changes=$((changes + 1))
        elif cmp -s "$SRC/$file" "$DST/$file"; then
            echo "OK        $file"
        else
            echo "CAMBIA    $file"
            changes=$((changes + 1))
        fi
    done

    echo

    if [[ "$changes" -eq 0 ]]; then
        echo "OK: Administración de Usuarios ya está actualizada en producción."
    else
        echo "PENDIENTE: $changes archivo(s) requieren publicación."
    fi

    echo "No se modificó ningún archivo (--check)."
    exit 0
fi

BACKUP_ROOT="${IMPE_USUARIOS_BACKUP_DIR:-$HOME/respaldos-crm}"
mkdir -p "$BACKUP_ROOT"
BACKUP="$(mktemp -d "$BACKUP_ROOT/usuarios-admin-$(date +%Y%m%d-%H%M%S)-XXXXXX")"

for file in "${FILES[@]}"; do
    if [[ -f "$DST/$file" ]]; then
        mkdir -p "$BACKUP/$(dirname "$file")"
        cp -p "$DST/$file" "$BACKUP/$file"
    fi

    mkdir -p "$DST/$(dirname "$file")"
    cp "$SRC/$file" "$DST/$file"
    chmod 0644 "$DST/$file"
    echo "PUBLICADO $file"
done

grep -Fq "data-system-role-dropdown" "$DST/app/views/usuarios/index.php"
grep -Fq "Selector de rol personalizado" "$DST/public/css/dashboard.css"

echo
echo "OK: selector de roles actualizado en Administración de Usuarios."
echo "Respaldo: $BACKUP"
echo "Recarga el navegador con Ctrl+F5."
