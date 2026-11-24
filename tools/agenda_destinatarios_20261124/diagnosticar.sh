#!/usr/bin/env bash
# Sólo compara fuentes; no conecta a la BD ni modifica archivos del sitio.
set -euo pipefail
umask 077
SOURCE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
LIVE="${IMPE_AGENDA_PUBLIC:-$HOME/domains/fundacionrededumex.org/public_html}"
PATCH="$SOURCE/cambio.patch"
cd "$LIVE"
if git apply --check "$PATCH" >/dev/null 2>&1; then
  echo 'DIAGNOSTICO: el parche original es compatible.'
  exit 0
fi
if git apply --check --ignore-space-change "$PATCH" >/dev/null 2>&1; then
  echo 'DIAGNOSTICO: compatible al ignorar espacios o finales de línea. Sitio intacto.'
  exit 0
fi

# Reconstruir la base del parche en una carpeta temporal privada. Nunca
# se revierte ni se escribe sobre los archivos de producción.
TMP="$(mktemp -d "${TMPDIR:-/tmp}/agenda-diagnostico.XXXXXX")"
cp -R "$SOURCE/files/." "$TMP/"
(
  cd "$TMP"
  git apply --reverse --exclude='database/**' "$PATCH"
)
echo 'DIAGNOSTICO: hay diferencias de código con la base. SITIO INTACTO.'
for file in \
  app/services/AgendaComentarioRepository.php \
  app/services/AgendaComentarioService.php \
  app/views/seguimiento_vinculacion/partials/agenda_comentarios.php \
  public/javascript/agenda_comentarios.js; do
  echo "ARCHIVO: $file"
  git diff --no-index --no-ext-diff --ignore-space-at-eol -- "$TMP/$file" "$LIVE/$file" || [[ "$?" == 1 ]]
done
echo "Comparación terminada. Temporales privados: $TMP"
