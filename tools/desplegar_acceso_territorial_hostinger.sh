#!/usr/bin/env bash
# Publica exclusivamente el acceso territorial por rol, protegiendo cambios locales.
# --check (por defecto) revisa todo sin modificar; --apply respalda y publica.
set -euo pipefail
umask 077
MODE="$1"
if [[ "$MODE" != "--check" && "$MODE" != "--apply" ]]; then
    echo "Uso: bash tools/desplegar_acceso_territorial_hostinger.sh --check|--apply" >&2
    exit 2
fi
SRC="$HOME/crm-preparacion"
DST="$HOME/domains/fundacionrededumex.org/public_html"
HELPER="app/helpers/DataTerritorialAccessPolicy.php"
FILES="app/models/DataTerritorialModel.php app/controllers/DataTerritorialController.php app/views/data_territorial/index.php"
if ! git -C "$SRC" rev-parse --is-inside-work-tree >/dev/null 2>&1 || [[ ! -f "$DST/index.php" ]]; then
    echo "ERROR: no se localizó el repositorio o el CRM público en Hostinger." >&2
    exit 1
fi
[[ -f "$SRC/$HELPER" ]] || { echo "ERROR: repositorio sin política nueva; ejecutar git pull." >&2; exit 1; }
if [[ -f "$DST/$HELPER" ]] && ! cmp -s "$DST/$HELPER" "$SRC/$HELPER"; then
    echo "CONFLICTO: helper personalizado en producción; no se ha modificado nada." >&2
    exit 1
fi
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
echo "1/4 Fusionando con personalizaciones existentes..."
for p in $FILES; do
    [[ -f "$SRC/$p" && -f "$DST/$p" ]] || { echo "ERROR: falta $p" >&2; exit 1; }
    mkdir -p "$TMP/$(dirname "$p")"
    if cmp -s "$SRC/$p" "$DST/$p"; then
        cp "$SRC/$p" "$TMP/$p"
        continue
    fi
    case "$p" in
      app/models/DataTerritorialModel.php) base="ae83c00ae79dbd09a920e5671b1cfb353ef3dfad";;
      app/controllers/DataTerritorialController.php) base="eec104a817a2d46640010ff4f43189d177e959f3";;
      app/views/data_territorial/index.php) base="6c28390eb42fc73b0a6abc799a410f8c107e111d";;
    esac
    git -C "$SRC" cat-file blob "$base" > "$TMP/base" || { echo "ERROR: falta historia Git; actualizar clon." >&2; exit 1; }
    git merge-file -p "$DST/$p" "$TMP/base" "$SRC/$p" > "$TMP/$p" || {
        echo "CONFLICTO: cambios incompatibles en $p. No se publicó ningún archivo." >&2
        exit 1
    }
done
mkdir -p "$TMP/app/helpers"
cp "$SRC/$HELPER" "$TMP/$HELPER"
echo "2/4 Validando la corrección..."
grep -Fq 'DataTerritorialAccessPolicy::resolver' "$TMP/app/models/DataTerritorialModel.php"
grep -Fq 'Consulta la información de los Estados registrados.' "$TMP/app/controllers/DataTerritorialController.php"
grep -Fq 'No hay Estados activos disponibles.' "$TMP/app/views/data_territorial/index.php"
for p in $FILES "$HELPER"; do php -l "$TMP/$p"; done
(cd "$SRC" && php tests/data_territorial_acceso_roles_smoke.php)

CHANGED=""
for p in $FILES "$HELPER"; do
    if [[ ! -f "$DST/$p" ]] || ! cmp -s "$TMP/$p" "$DST/$p"; then
        CHANGED="$CHANGED $p"
    fi
done
if [[ -z "$CHANGED" ]]; then
    echo "OK: corrección ya publicada. No se modificó nada."
    exit 0
fi
echo "Archivos por actualizar:$CHANGED"
if [[ "$MODE" == "--check" ]]; then
    echo "OK: comprobación terminada SIN modificar producción."
    exit 0
fi

echo "3/4 Respaldando..."
mkdir -p "$HOME/respaldos-crm"
BACKUP="$(mktemp -d "$HOME/respaldos-crm/territorial-roles-$(date +%Y%m%d-%H%M%S)-XXXXXX")"
for p in $CHANGED; do
    if [[ -f "$DST/$p" ]]; then
        mkdir -p "$BACKUP/$(dirname "$p")"
        cp -p "$DST/$p" "$BACKUP/$p"
    fi
done
echo "4/4 Publicando cuatro archivos PHP, sin tocar otros módulos..."
mkdir -p "$DST/app/helpers"
for p in $CHANGED; do
    cp "$TMP/$p" "$DST/$p"
done
for p in $FILES "$HELPER"; do
    php -l "$DST/$p"
    cmp -s "$DST/$p" "$TMP/$p" || { echo "ERROR al verificar $p" >&2; exit 1; }
done
echo "OK: acceso territorial por rol publicado sin cambios en SQL, telefonía ni permisos."
echo "Respaldo: $BACKUP"
echo "Cierra la sesión de Marketing y vuelve a entrar."
