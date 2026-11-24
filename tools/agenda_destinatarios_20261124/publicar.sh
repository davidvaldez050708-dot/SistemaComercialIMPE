#!/usr/bin/env bash
set -euo pipefail

MODE="${1:---check}"
[[ "$#" -le 1 && ( "$MODE" == --check || "$MODE" == --apply ) ]] || {
  echo 'Uso: bash publicar.sh --check | --apply' >&2; exit 2;
}
SOURCE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
LIVE="${IMPE_AGENDA_PUBLIC:-$HOME/domains/fundacionrededumex.org/public_html}"
BACKUPS="${IMPE_AGENDA_BACKUPS:-$HOME/respaldos-crm}"
PATCH="$SOURCE/cambio.patch"
[[ -s "$PATCH" && -f "$LIVE/index.php" ]] || {
  echo 'ERROR: falta el parche completo o la raíz del CRM.' >&2; exit 1;
}
cd "$LIVE"

ALREADY=0
if git apply --reverse --check "$PATCH" >/dev/null 2>&1; then
  ALREADY=1
else
  git apply --check "$PATCH"
fi
for file in app/services/AgendaComentarioRepository.php app/services/AgendaComentarioService.php app/views/seguimiento_vinculacion/partials/agenda_comentarios.php; do
  php -l "$SOURCE/files/$file" >/dev/null
done

# Comprobar conexión y columnas históricas sin modificar la base de datos.
php -r '
require getcwd()."/config/db_connection.php";
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db=(new Database())->connect(false);
$db->query("SELECT id,destinatario_id,privado FROM agenda_comentarios LIMIT 0");
$db->close();
'
if [[ "$MODE" == --check ]]; then
  echo 'OK: check compatible. No se modificaron archivos ni base de datos.'
  exit 0
fi

LOCK="$(dirname "$LIVE")/.agenda-destinatarios.lock"
mkdir "$LOCK" 2>/dev/null || { echo 'ERROR: otra instalación está en curso.' >&2; exit 1; }
CHANGED=0
finish() {
  local status=$?
  trap - EXIT
  if [[ "$status" != 0 && "$CHANGED" == 1 ]]; then
    git apply --reverse "$PATCH" || echo 'ERROR: revisar restauración con el respaldo.' >&2
  fi
  rmdir "$LOCK"
  exit "$status"
}
trap finish EXIT

if [[ "$ALREADY" == 0 ]]; then
  BACKUP="$BACKUPS/agenda-destinatarios-$(date +%Y%m%d-%H%M%S)-$$"
  (umask 077; mkdir -p "$BACKUP")
  tar -czf "$BACKUP/archivos-anteriores.tar.gz" \
    app/services/AgendaComentarioRepository.php \
    app/services/AgendaComentarioService.php \
    app/views/seguimiento_vinculacion/partials/agenda_comentarios.php \
    public/javascript/agenda_comentarios.js
  git apply "$PATCH"
  CHANGED=1
  echo "Respaldo: $BACKUP"
fi

php -r '
require getcwd()."/config/db_connection.php";
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db=(new Database())->connect(false);
$sql=file_get_contents("database/migrations/2026_11_24_agenda_comentarios_destinatarios.sql");
if ($sql===false) throw new RuntimeException("Falta la migración.");
$db->query($sql);
$db->query("SELECT comentario_id,usuario_id FROM agenda_comentarios_destinatarios LIMIT 0");
$db->close();
'
git apply --reverse --check "$PATCH"
CHANGED=0
echo 'OK: apply terminado; archivos y tabla de destinatarios instalados.'
