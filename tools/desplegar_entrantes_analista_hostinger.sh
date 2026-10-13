#!/usr/bin/env bash
# Fusiona exclusivamente las piezas de entrantes de Analistas.
# --check es de solo lectura; --apply respalda y aplica el mismo parche.
set -euo pipefail
umask 077
MODE="${1:---check}"
[[ "$MODE" == "--check" || "$MODE" == "--apply" ]] || {
  echo "Uso: --check o --apply" >&2; exit 2;
}
SRC="${IMPE_ENTRANTES_SOURCE:-$HOME/crm-preparacion}"
LIVE="${IMPE_ENTRANTES_PUBLIC:-$HOME/domains/fundacionrededumex.org/public_html}"
BACKUPS="${IMPE_ENTRANTES_BACKUPS:-$HOME/respaldos-crm}"
BASE="cc7eba5c9f5819cb0b94c8f85c83e0f08ee105f3"
FILES=(
  app/controllers/SeguimientoVinculacionController.php
  app/models/SeguimientoVinculacionModel.php
  app/services/TelefoniaEntrantesAnalistaService.php
  app/views/layout/dashboard_head.php
  app/views/layout/dashboard_footer.php
  app/views/seguimiento_vinculacion/index.php
  app/views/seguimiento_vinculacion/estado.php
  app/views/seguimiento_vinculacion/llamadas_entrantes.php
  app/views/telefonia/shell.php
  public/javascript/telefonia_shell.js
  public/javascript/seguimiento_entrantes_analista.js
  public/css/seguimiento_entrantes_analista.css
)
[[ -d "$SRC/.git" && -d "$LIVE" ]] || {
  echo "ERROR: no se encontró crm-preparacion o public_html." >&2; exit 1;
}
git -C "$SRC" cat-file -e "$BASE^{commit}" || {
  echo "ERROR: no se encontró el commit base." >&2; exit 1;
}
[[ -z "$(git -C "$SRC" status --porcelain -- "${FILES[@]}")" ]] || {
  echo "ERROR: cambios locales en archivos de esta función." >&2; exit 1;
}
echo "1/4 Validando la fuente..."
for file in "${FILES[@]}"; do
  [[ -f "$SRC/$file" ]] || { echo "ERROR: falta $file" >&2; exit 1; }
  if [[ "$file" == *.php ]]; then php -l "$SRC/$file" >/dev/null; fi
done
if command -v node >/dev/null 2>&1; then
  node --check "$SRC/public/javascript/telefonia_shell.js"
  node --check "$SRC/public/javascript/seguimiento_entrantes_analista.js"
fi
php "$SRC/tests/telefonia_analista_entrantes_smoke.php"

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
git -C "$SRC" diff --binary "$BASE" HEAD -- "${FILES[@]}" > "$TMP/change.patch"
[[ -s "$TMP/change.patch" ]] || {
  echo "ERROR: no se encontraron cambios para desplegar." >&2; exit 1;
}
if grep -Eq '^(---|\+\+\+) [ab]/(config/|\.env|public/css/telefonia_shell.css)' "$TMP/change.patch"; then
  echo "ERROR: el parche contiene archivos no autorizados." >&2; exit 1;
fi

echo "2/4 Comprobando compatibilidad con producción..."
if (cd "$LIVE" && git apply --reverse --check "$TMP/change.patch" >/dev/null 2>&1); then
  echo "OK: la corrección ya está aplicada en producción."; exit 0;
fi
if ! (cd "$LIVE" && git apply --check "$TMP/change.patch"); then
  echo "CONFLICTO: el CSS o el código vivo no coincide con el parche seguro." >&2
  echo "No se modificó nada. Comparte esta salida antes de continuar." >&2
  exit 1
fi
if [[ "$MODE" == "--check" ]]; then
  echo "OK: verificación de solo lectura. Ejecuta --apply para publicar."; exit 0;
fi

echo "3/4 Respaldando los archivos antes de aplicar..."
mkdir -p "$BACKUPS"
BACKUP="$BACKUPS/entrantes-analista-$(date +%Y%m%d-%H%M%S)-$$"
mkdir -p "$BACKUP"
NEWFILES=()
for file in "${FILES[@]}"; do
  if [[ -f "$LIVE/$file" ]]; then
    mkdir -p "$BACKUP/$(dirname "$file")"
    cp -p "$LIVE/$file" "$BACKUP/$file"
  else
    NEWFILES+=("$file")
  fi
done
rollback() {
  local code=$?
  if [[ "$code" -ne 0 ]]; then
    echo "ERROR: restaurando archivos del respaldo..." >&2
    for file in "${FILES[@]}"; do
      if [[ -f "$BACKUP/$file" ]]; then cp -p "$BACKUP/$file" "$LIVE/$file"; fi
    done
    for file in "${NEWFILES[@]}"; do rm -f "$LIVE/$file"; done
    echo "Respaldo: $BACKUP" >&2
  fi
  rm -rf "$TMP"
  exit "$code"
}
trap rollback EXIT

echo "4/4 Aplicando el parche y comprobando integridad..."
(cd "$LIVE" && git apply "$TMP/change.patch")
for file in "${FILES[@]}"; do
  if [[ "$file" == *.php ]]; then php -l "$LIVE/$file" >/dev/null; fi
done
(cd "$LIVE" && git apply --reverse --check "$TMP/change.patch")
trap - EXIT
rm -rf "$TMP"
echo "OK: entrantes publicados. No se tocó Zadarma, MySQL ni el CSS compartido."
echo "La tabla auxiliar se crea al abrir la bandeja, si MySQL permite CREATE."
echo "Respaldo: $BACKUP"
