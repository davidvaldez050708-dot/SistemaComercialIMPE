#!/usr/bin/env bash
# Restablece el ancho de los modales compartidos sin sustituir el CSS personalizado.
# --check no escribe en producción; --apply hace respaldo y publica un único CSS.
set -euo pipefail
umask 077

MODE="${1:---check}"
[[ "$MODE" == "--check" || "$MODE" == "--apply" ]] || {
  echo "Uso: bash tools/desplegar_anchos_modales_hostinger.sh --check|--apply" >&2
  exit 2
}

SRC="${IMPE_MODALES_SOURCE:-$HOME/crm-preparacion}"
LIVE="${IMPE_MODALES_PUBLIC:-$HOME/domains/fundacionrededumex.org/public_html}"
BACKUPS="${IMPE_MODALES_BACKUPS:-$HOME/respaldos-crm}"
REL="public/css/responsive_sistema.css"

[[ -d "$SRC/.git" && -f "$SRC/tools/ajustar_anchos_modales.php" &&
   -f "$LIVE/$REL" && ! -L "$LIVE/$REL" ]] || {
  echo "ERROR: no se localizaron el repositorio o el CSS de producción." >&2
  exit 1
}
grep -Fq 'No limitar los diálogos aquí' "$SRC/$REL" || {
  echo "ERROR: primero actualiza crm-preparacion desde GitHub." >&2
  exit 1
}
STAGING="$(mktemp -d)"
trap 'rm -rf "$STAGING"' EXIT

echo "1/3 Preparando parche sin modificar el CRM..."
php "$SRC/tools/ajustar_anchos_modales.php" "$LIVE/$REL" "$STAGING/responsive_sistema.css"
if cmp -s "$STAGING/responsive_sistema.css" "$LIVE/$REL"; then
  echo "OK: los modales ya tienen la corrección; no hay nada que publicar."
  exit 0
fi

echo "2/3 Detectados cambios exclusivamente en $REL"
if [[ "$MODE" == "--check" ]]; then
  echo "OK: comprobación de solo lectura. Ejecuta --apply para publicar."
  exit 0
fi

mkdir -p "$BACKUPS"
BACKUP="$BACKUPS/modales-ancho-$(date +%Y%m%d-%H%M%S)-$$.css"
cp -p "$LIVE/$REL" "$BACKUP"
echo "Respaldo: $BACKUP"

echo "3/3 Aplicando CSS en Hostinger..."
cp "$STAGING/responsive_sistema.css" "$LIVE/$REL"
cmp -s "$STAGING/responsive_sistema.css" "$LIVE/$REL" || {
  echo "ERROR: no coincide el archivo publicado. Respaldo: $BACKUP" >&2
  exit 1
}
echo "OK: anchos restaurados en Usuarios, Roles, Territorios y demás modales."
echo "Sin cambios en PHP, base de datos, Zadarma ni telefonía."
