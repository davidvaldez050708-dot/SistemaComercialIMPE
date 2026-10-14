#!/usr/bin/env bash
# Diagnóstico de sólo lectura: NO publica, NO cambia el CRM ni sus permisos.
# Muestra exclusivamente los bloques incompatibles del shell del teléfono.
set -euo pipefail
umask 077
SOURCE="${IMPE_HISTORY_SOURCE:-$HOME/crm-preparacion}"
LIVE="${IMPE_HISTORY_PUBLIC:-$HOME/domains/fundacionrededumex.org/public_html}"
REL="public/javascript/telefonia_shell.js"
BASE="9c6af2f292f2bde1a01b0aeb0cb88106327518c0"
TARGET="d63b667e8e79ed95bf33b64d356f6d72982ca2ce"

[[ -d "$SOURCE/.git" && -f "$LIVE/$REL" && ! -L "$LIVE/$REL" ]] || {
  echo "ERROR: no se encontró el shell de producción o repositorio de preparación." >&2
  exit 1
}
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
git -C "$SOURCE" show "$BASE:$REL" > "$TMP/base.js"
git -C "$SOURCE" show "$TARGET:$REL" > "$TMP/objetivo.js"
cp "$LIVE/$REL" "$TMP/produccion.js"
echo "DIAGNOSTICO TELEFONIA - SOLO LECTURA"
echo "Archivo: $REL"
echo "SHA Git producción: $(git hash-object "$TMP/produccion.js")"
echo "Líneas en producción: $(wc -l < "$TMP/produccion.js")"
echo "Líneas objetivo: $(wc -l < "$TMP/objetivo.js")"

# Buscar si lo publicado coincide exactamente con un commit anterior
# de este MISMO archivo, sin imprimir su contenido completo.
BLOB="$(git hash-object "$TMP/produccion.js")"
MATCH=''
while IFS= read -r REV; do
  FOUND="$(git -C "$SOURCE" rev-parse "$REV:$REL" 2>/dev/null || true)"
  if [[ "$FOUND" == "$BLOB" ]]; then MATCH="$REV"; break; fi
done < <(git -C "$SOURCE" log --all --format=%H -- "$REL")
if [[ -n "$MATCH" ]]; then
  echo "Versión publicada localizada en Git: $MATCH"
else
  echo "Versión publicada: contiene cambios que no coinciden con un commit conocido."
fi

# El tercer archivo es la versión objetivo; el primero es producción.
# Modo --diff3 incluye el ancestro para facilitar una fusión quirúrgica.
set +e
git merge-file --diff3 -p "$TMP/produccion.js" "$TMP/base.js" \
  "$TMP/objetivo.js" >"$TMP/merge.txt"
MERGE_STATUS=$?
set -e
echo "Resultado del merge de tres versiones: $MERGE_STATUS"
echo "BLOQUES DE CONFLICTO (máximo 210 líneas):"
awk '
BEGIN { on=0; n=0; conflicts=0; clipped=0 }
/^<<<<<<< / {on=1; conflicts++}
on {
  if (n < 210) { print; n++ } else { clipped++ }
}
/^>>>>>>> / {on=0}
END {
  printf "CONFLICTOS DETECTADOS: %d\n", conflicts
  if (clipped) printf "LINEAS OMITIDAS: %d\n", clipped
}' "$TMP/merge.txt"
echo "FIN DIAGNOSTICO. Ningún archivo de producción fue modificado."
