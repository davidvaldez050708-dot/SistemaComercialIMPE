#!/usr/bin/env bash
# Corrección VISUAL exclusiva del botón Cerrar en el MISMO modal del Analista.
# No toca SDK, host, shell, PBX, webhook, listado, historial ni Marketing.
set -euo pipefail
umask 077
MODE="${1:---check}"
[[ "$MODE" == "--check" || "$MODE" == "--apply" ]] || {
  echo "Uso: bash $0 --check|--apply" >&2
  exit 2
}
SOURCE="${IMPE_BUTTONS_SOURCE:-$HOME/crm-preparacion}"
LIVE="${IMPE_BUTTONS_PUBLIC:-$HOME/domains/fundacionrededumex.org/public_html}"
BACKUPS="${IMPE_BUTTONS_BACKUPS:-$HOME/respaldos-crm}"
BASE="818f0219a1d5eb8333fde9e3e82ea7d2daa0bb23"
TARGET="8a07d65f5417cfa6fad41e89aa314c3ac7c216d5"
FILE="public/javascript/telefonia_analista_historial_pasivo.js"
[[ -d "$SOURCE/.git" && -f "$LIVE/$FILE" && ! -L "$LIVE/$FILE" ]] || {
  echo "ERROR: faltan repositorio o módulo pasivo publicado." >&2
  exit 1
}
for revision in "$BASE" "$TARGET"; do
  git -C "$SOURCE" cat-file -e "${revision}^{commit}" || {
    echo "ERROR: falta la revisión $revision; ejecuta git pull --ff-only origin main." >&2
    exit 1
  }
done
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
mkdir -p "$TMP/public/javascript"
cp -p "$LIVE/$FILE" "$TMP/$FILE"
LIVE_SHA="$(sha256sum "$LIVE/$FILE" | cut -d' ' -f1)"
git -C "$SOURCE" diff --binary "$BASE" "$TARGET" -- "$FILE" > "$TMP/patch"
[[ -s "$TMP/patch" ]] || {
  echo "ERROR: no se encontró un cambio de distribución de botones." >&2
  exit 1
}
# El parche sólo debe contener el CSS del mismo componente.
git -C "$SOURCE" show "$BASE:$FILE" > "$TMP/old.js"
git -C "$SOURCE" show "$TARGET:$FILE" > "$TMP/new.js"
php -r '
$old = file_get_contents($argv[1]);
$new = file_get_contents($argv[2]);
$start = "    finishedCss.textContent = [";
$end = "    root.document.head.appendChild(finishedCss);";
foreach ([$old, $new] as $code) {
    if (substr_count($code, $start) !== 1 || substr_count($code, $end) !== 1) {
        fwrite(STDERR, "ERROR: estilos de modal no reconocidos.\n");
        exit(1);
    }
}
$withoutCss = static function ($code) use ($start, $end) {
    [$before, $after] = explode($start, $code, 2);
    [, $suffix] = explode($end, $after, 2);
    return $before . $start . "/* CSS */" . $end . $suffix;
};
if ($withoutCss($old) !== $withoutCss($new) ||
    !str_contains($new, "display:flex!important;flex-direction:row;") ||
    !str_contains($new, "data-analyst-webrtc-finished")) {
    fwrite(STDERR, "ERROR: el parche modifica algo distinto al estilo del modal.\n");
    exit(1);
}
' "$TMP/old.js" "$TMP/new.js"

if (cd "$TMP" && git apply --reverse --check "$TMP/patch" >/dev/null 2>&1); then
  echo "OK: botones del modal final ya alineados. No se requiere publicar."
  exit 0
fi
if ! (cd "$TMP" && git apply --check "$TMP/patch" >/dev/null 2>&1); then
  echo "CONFLICTO: el módulo pasivo de producción tiene cambios diferentes." >&2
  echo "No se modificó nada. Comparte esta salida." >&2
  exit 1
fi
(cd "$TMP" && git apply "$TMP/patch")
if command -v node >/dev/null 2>&1; then node --check "$TMP/$FILE"; fi

if [[ "$MODE" == "--check" ]]; then
  echo "OK: botones compatibles. 1 JavaScript visual; PRODUCCIÓN INTACTA."
  echo "Intactos: telefonia_host.js, telefonia_shell.js, Zadarma, Marketing e historial."
  echo "Puedes ejecutar --apply."
  exit 0
fi
CURRENT_SHA="$(sha256sum "$LIVE/$FILE" | cut -d' ' -f1)"
[[ "$CURRENT_SHA" == "$LIVE_SHA" ]] || {
  echo "CONFLICTO: el archivo cambió durante el preflight." >&2
  echo "No se modificó nada. Comparte esta salida." >&2
  exit 1
}
mkdir -p "$BACKUPS"
BACKUP="$BACKUPS/botones-modal-analista-$(date +%Y%m%d-%H%M%S)-$$.js"
cp -p "$LIVE/$FILE" "$BACKUP"
if ! cp -p "$TMP/$FILE" "$LIVE/$FILE"; then
  cp -p "$BACKUP" "$LIVE/$FILE"
  echo "ERROR: no se pudo actualizar; restaurado el respaldo." >&2
  exit 1
fi
chmod 0644 "$LIVE/$FILE"
if ! cmp -s "$TMP/$FILE" "$LIVE/$FILE"; then
  cp -p "$BACKUP" "$LIVE/$FILE"
  echo "ERROR: verificación fallida; restaurado el respaldo." >&2
  exit 1
fi
echo "OK: Registrar resultado y Cerrar alineados en el mismo modal."
echo "Respaldo: $BACKUP"
echo "Recepción 100→101, Zadarma, Marketing e historial no modificados."
