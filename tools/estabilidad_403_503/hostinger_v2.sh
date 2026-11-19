#!/usr/bin/env bash
# Ejecutar este archivo guardado en disco, junto al publicador y reconciled/.
set -euo pipefail
umask 077

MODE="${1:---check}"
if [[ "$MODE" != --check && "$MODE" != --apply && "$MODE" != --restore-check && "$MODE" != --restore ]]; then
  printf '%s\n' 'Uso: bash hostinger_v2.sh --check|--apply|--restore-check RESPALDO|--restore RESPALDO' >&2
  exit 2
fi
SCRIPT_DIR="$(cd -- "$(dirname -- "$0")" && pwd -P)"
command -v php >/dev/null || { printf '%s\n' 'Falta PHP CLI.' >&2; exit 1; }
ROOT="${IMPE_CRM_ROOT:-}"
if [[ -z "$ROOT" ]]; then
  MATCHES=0
  for candidate in "$HOME"/domains/*/public_html; do
    [[ -d "$candidate" && -f "$candidate/config/db_connection.php" ]] || continue
    if php -r '$m=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);$h=hash_file("sha256",$argv[2]);$f=$m["files"]["config/db_connection.php"];exit(in_array($h,[$f["before"],$f["after"]],true)?0:1);' "$SCRIPT_DIR/reconciled/release.json" "$candidate/config/db_connection.php"; then
      ROOT="$candidate"
      MATCHES=$((MATCHES + 1))
    fi
  done
  if [[ "$MATCHES" -ne 1 ]]; then
    printf '%s\n' 'No hay una única instalación identificable. Define IMPE_CRM_ROOT con la ruta real y repite.' >&2
    exit 1
  fi
fi
[[ -d "$ROOT" && ! -L "$ROOT" ]] || { printf '%s\n' 'Raíz inválida o enlace simbólico.' >&2; exit 1; }
printf 'Directorio CRM: %s\n' "$ROOT"
if [[ "$MODE" == --check ]]; then
  [[ "$#" -le 1 ]] || exit 2
  exec php "$SCRIPT_DIR/publicar_conciliada.php" --check --root "$ROOT"
fi
if [[ "$MODE" == --apply ]]; then
  [[ "$#" -eq 1 ]] || exit 2
  BACKUPS="${IMPE_CRM_BACKUPS:-$HOME/respaldos-crm}"
  [[ -d "$BACKUPS" ]] || { printf 'Crea primero el directorio privado de respaldos: %s\n' "$BACKUPS" >&2; exit 1; }
  exec php "$SCRIPT_DIR/publicar_conciliada.php" --apply --root "$ROOT" --backup-root "$BACKUPS"
fi
[[ "$#" -eq 2 && -n "$2" ]] || { printf '%s\n' 'Indica el directorio exacto de respaldo.' >&2; exit 2; }
exec php "$SCRIPT_DIR/publicar_conciliada.php" "$MODE" "$2" --root "$ROOT"
