#!/usr/bin/env bash
set -euo pipefail
umask 077

MODE="${1:---check}"
[[ "$#" -le 1 && ( "$MODE" == --check || "$MODE" == --apply ) ]] || {
  echo 'Uso: bash publicar.sh --check | --apply' >&2; exit 2;
}
SOURCE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
LIVE="${IMPE_AGENDA_PUBLIC:-$HOME/domains/fundacionrededumex.org/public_html}"
BACKUPS="${IMPE_AGENDA_BACKUPS:-$HOME/respaldos-crm}"
[[ -f "$LIVE/index.php" ]] || { echo 'ERROR: no se encontró la raíz del CRM.' >&2; exit 1; }
LIVE="$(cd "$LIVE" && pwd -P)"
TMP="$(mktemp -d "${TMPDIR:-/tmp}/agenda-marketing-v3.XXXXXX")"
LOCK="$(dirname "$LIVE")/.agenda-destinatarios.lock"
LOCKED=0
WRITTEN=()
finish() {
  local status=$?
  trap - EXIT
  if [[ "$status" != 0 ]]; then
    for file in "${WRITTEN[@]}"; do
      if [[ -f "$TMP/original/$file" ]]; then
        cp -p -- "$TMP/original/$file" "$LIVE/$file"
      else
        rm -f -- "$LIVE/$file"
      fi
    done
    [[ "${#WRITTEN[@]}" == 0 ]] || echo 'Se restauraron los archivos anteriores.' >&2
  fi
  [[ "$LOCKED" == 0 ]] || rmdir "$LOCK"
  exit "$status"
}
trap finish EXIT
if [[ "$MODE" == --apply ]]; then
  mkdir "$LOCK" 2>/dev/null || { echo 'ERROR: otra instalación está en curso.' >&2; exit 1; }
  LOCKED=1
fi

php "$SOURCE/preparar.php" preparar "$LIVE" "$TMP"
for file in app/services/AgendaMarketingNotificationService.php app/controllers/ConvocatoriaNotificacionController.php; do
  php -l "$TMP/stage/$file" >/dev/null
done
cd "$LIVE"
if [[ "$MODE" == --check ]]; then
  echo "OK: CHECK marketing-agenda-v3 compatible con $LIVE. No se modificó el sitio ni la base de datos."
  exit 0
fi

php "$SOURCE/preparar.php" listar "$LIVE" "$TMP" > "$TMP/cambios.txt"
mapfile -t CHANGED < "$TMP/cambios.txt"
if [[ "${#CHANGED[@]}" != 0 ]]; then
  BACKUP="$BACKUPS/agenda-marketing-v3-$(date +%Y%m%d-%H%M%S)-$$"
  mkdir -p "$BACKUP"
  tar -czf "$BACKUP/archivos-anteriores.tar.gz" -C "$TMP/original" .
  cp "$TMP/plan.json" "$BACKUP/plan.json"
  echo "Respaldo: $BACKUP"
fi
php "$SOURCE/preparar.php" antes "$LIVE" "$TMP"
for file in "${CHANGED[@]}"; do
  mkdir -p "$(dirname "$LIVE/$file")"
  install="$LIVE/$file.agenda-marketing-v3-$$"
  cp "$TMP/stage/$file" "$install"
  if [[ -f "$LIVE/$file" ]]; then chmod --reference="$LIVE/$file" "$install"; else chmod 644 "$install"; fi
  WRITTEN+=("$file")
  mv -f -- "$install" "$LIVE/$file"
done
php "$SOURCE/preparar.php" despues "$LIVE" "$TMP"
echo "OK: APPLY marketing-agenda-v3 terminado en $LIVE. Comentarios y sonido de Agenda habilitados en la campana de Marketing."
echo 'Recarga Marketing y prueba enviándole un comentario NUEVO desde otra cuenta; los comentarios ya anunciados no se repiten.'
