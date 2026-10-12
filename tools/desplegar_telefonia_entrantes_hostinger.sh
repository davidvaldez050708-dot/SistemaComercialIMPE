#!/usr/bin/env bash
# Actualización visual de WebRTC entrante; respaldo previo y fusión con cambios locales.
set -euo pipefail
umask 077
SRC="$HOME/crm-preparacion"
DST="$HOME/domains/fundacionrededumex.org/public_html"
RESP="$HOME/respaldos-crm"
FILES="public/css/telefonia_host.css public/javascript/telefonia_host.js public/css/telefonia_shell.css public/javascript/telefonia_shell.js public/javascript/telefonia_persistente.js app/views/telefonia/shell.php"
[[ -d "$SRC/.git" && -f "$DST/index.php" ]] || {
  echo "ERROR: falta el repositorio o la raíz de producción" >&2; exit 1;
}
TEMP="$(mktemp -d)"
trap 'rm -rf "$TEMP"' EXIT
echo "1/4 Preparando fusión de los archivos de teléfono..."
for p in $FILES; do
  [[ -f "$SRC/$p" && -f "$DST/$p" ]] || { echo "ERROR: falta $p" >&2; exit 1; }
  mkdir -p "$TEMP/$(dirname "$p")"
  if cmp -s "$SRC/$p" "$DST/$p"; then
    cp "$SRC/$p" "$TEMP/$p"; continue
  fi
  case "$p" in
    public/css/telefonia_host.css) BASE="361172edf4a61c884cdfa60135152f6b84590759";;
    public/javascript/telefonia_host.js) BASE="1590a267840e6ac5d341254fdb760d4deb04b4b7";;
    public/css/telefonia_shell.css) BASE="13cdd5876bedf6313168c53c13666fe602ebe89a";;
    public/javascript/telefonia_shell.js) BASE="2352c5aa9a1e2c2cb9329cfba79a785c83b5792e";;
    public/javascript/telefonia_persistente.js) BASE="94d7e74fb8a1dca3e5afd45e44db48a46be9dfce";;
    app/views/telefonia/shell.php) BASE="763470485ce10bd7a74ec2f938eca3bae0877e31";;
  esac
  git -C "$SRC" cat-file blob "$BASE" > "$TEMP/base" || {
    echo "ERROR: falta un objeto Git histórico, actualiza el repositorio" >&2; exit 1;
  }
  LIVE="$DST/$p"
  if [[ "$p" == "public/css/telefonia_host.css" ]]; then
    # Sólo el bloque incondicional 1x1 identificado en Hostinger se resuelve
    # puntualmente. No difuminar cambios locales usando un merge conflictivo.
    php "$SRC/tools/normalizar_telefonia_host_css.php" \
      "$DST/$p" "$SRC/$p" "$TEMP/telefonia_host_normalizado.css" || {
        echo "CONFLICTO: CSS de producción distinto del patrón reconocido; no se publicó nada." >&2
        exit 1
      }
    if grep -Fq 'body > *:not(.telephony-host-sentinel)' "$DST/$p"; then
      cp "$TEMP/telefonia_host_normalizado.css" "$TEMP/$p"
      continue
    fi
    LIVE="$TEMP/telefonia_host_normalizado.css"
  fi
  if git merge-file -p -L HOSTINGER -L BASE -L GITHUB \
      "$LIVE" "$TEMP/base" "$SRC/$p" > "$TEMP/$p"; then
    continue
  fi
  # Sólo se admiten los cuatro bloques exactos vistos en el diagnóstico.
  # Desconocidos, repetidos o cambiados bloquean TODO el despliegue.
  mv "$TEMP/$p" "$TEMP/merged-unresolved"
  if ! php "$SRC/tools/resolver_conflictos_telefonia.php" \
      "$p" "$TEMP/merged-unresolved" "$SRC/$p" "$TEMP/$p"; then
    echo "CONFLICTO: $p tiene cambios diferentes a los aprobados; no se publicó nada." >&2
    exit 1
  fi
done
echo "2/4 Validando antes de tocar producción..."
grep -Fq 'El SDK conserva sus propios botones' "$TEMP/public/javascript/telefonia_host.js"
grep -Fq 'hostReady: widgetReady' "$TEMP/public/javascript/telefonia_host.js"
grep -Fq 'data-shell-call-help' "$TEMP/app/views/telefonia/shell.php"
if grep -Fq 'body > *:not(.telephony-host-sentinel)' "$TEMP/public/css/telefonia_host.css" ||
   grep -Fq 'z-index: 2147483647' "$TEMP/public/css/telefonia_host.css"; then
  echo "ERROR: persisten reglas que ocultan el botón de contestar." >&2; exit 1
fi
grep -Fq 'incomingInShell' "$TEMP/public/javascript/telefonia_persistente.js"
if grep -Fq 'observerNativo' "$TEMP/public/javascript/telefonia_host.js"; then
  echo "ERROR: permanece la manipulación previa del SDK" >&2; exit 1
fi
if grep -Fq "raiz.style.setProperty(" "$TEMP/public/javascript/telefonia_host.js" ||
   grep -Fq "width: 3px; height: 3px" "$TEMP/public/css/telefonia_shell.css" ||
   grep -Fq "panel.hidden = !show || managedByShell;" "$TEMP/public/javascript/telefonia_persistente.js"; then
  echo "ERROR: quedan reglas antiguas en los controles entrantes." >&2; exit 1
fi
php -l "$TEMP/app/views/telefonia/shell.php"
if command -v node >/dev/null 2>&1; then
  for p in public/javascript/telefonia_host.js public/javascript/telefonia_shell.js public/javascript/telefonia_persistente.js; do
    node --check "$TEMP/$p"
  done
fi
MODIFIED=0
for p in $FILES; do
  if ! cmp -s "$TEMP/$p" "$DST/$p"; then MODIFIED=1; break; fi
done
if [[ "$MODIFIED" == "0" ]]; then
  echo "OK: recepción ya publicada, ningún archivo modificado."; exit 0
fi
echo "3/4 Respaldando producción..."
mkdir -p "$RESP"
BACKUP="$(mktemp -d "$RESP/telefonia-entrantes-$(date +%Y%m%d-%H%M%S)-XXXXXX")"
for p in $FILES; do
  mkdir -p "$BACKUP/$(dirname "$p")"
  cp -p "$DST/$p" "$BACKUP/$p"
done
echo "4/4 Publicando exclusivamente los seis archivos WebRTC..."
for p in $FILES; do cp "$TEMP/$p" "$DST/$p"; done
for p in $FILES; do
  cmp -s "$TEMP/$p" "$DST/$p" || { echo "ERROR: $p difiere tras publicación" >&2; exit 1; }
done
echo "OK: recepción WebRTC actualizada. Sin cambios en Zadarma, MySQL ni datos."
echo "Respaldo: $BACKUP"
echo "Cerrar todas las sesiones del CRM y abrir una nueva para comprobar."
