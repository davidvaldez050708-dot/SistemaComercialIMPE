#!/usr/bin/env bash
# Ensayo con archivos históricos, localizaciones distintas y un conflicto real.
set -euo pipefail
trap 'echo "ERROR de simulación Hostinger en línea $LINENO" >&2' ERR
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
SITE="$TMP/domains/fundacionrededumex.org/public_html"
mkdir -p "$SITE/public/javascript" "$SITE/public/css" "$SITE/app/views/telefonia"
touch "$SITE/index.php"
ln -s "$ROOT" "$TMP/crm-preparacion"
FILES="public/javascript/telefonia_host.js public/javascript/telefonia_shell.js public/css/telefonia_shell.css app/views/telefonia/host.php app/views/telefonia/shell.php"
for p in $FILES; do
  git -C "$ROOT" show "00e79124af6b77459e79fd8f47437ac2c2eaf1dc:$p" > "$SITE/$p"
done
echo '/* ESTILO-LOCAL-PRESERVAR */' >> "$SITE/public/css/telefonia_shell.css"
echo '// NOTA-LOCAL-PRESERVAR' >> "$SITE/public/javascript/telefonia_host.js"
deploy() { HOME="$TMP" bash "$ROOT/tools/desplegar_modal_entrantes_hostinger.sh" "$1"; }
BEFORE="$(sha256sum "$SITE/public/javascript/telefonia_host.js")"
deploy --check
[[ "$BEFORE" == "$(sha256sum "$SITE/public/javascript/telefonia_host.js")" ]]
[[ ! -e "$SITE/public/javascript/telefonia_entrantes_bridge.js" ]]
deploy --apply
grep -Fq 'NOTA-LOCAL-PRESERVAR' "$SITE/public/javascript/telefonia_host.js"
grep -Fq 'ESTILO-LOCAL-PRESERVAR' "$SITE/public/css/telefonia_shell.css"
grep -Fq 'data-incoming-answer' "$SITE/app/views/telefonia/shell.php"
grep -Fq 'IMPE_ZADARMA_NATIVE_BRIDGE_FACTORY' "$SITE/public/javascript/telefonia_host.js"
test -f "$SITE/public/javascript/telefonia_entrantes_bridge.js"
[[ "$(stat -c '%a' "$SITE/public/javascript/telefonia_entrantes_bridge.js")" == 644 ]]
chmod 0600 "$SITE/public/javascript/telefonia_entrantes_bridge.js"
deploy --check
[[ "$(stat -c '%a' "$SITE/public/javascript/telefonia_entrantes_bridge.js")" == 600 ]]
deploy --apply
[[ "$(stat -c '%a' "$SITE/public/javascript/telefonia_entrantes_bridge.js")" == 644 ]]
deploy --apply
[[ "$(find "$TMP/respaldos-crm" -type f | wc -l)" -eq 6 ]]
git -C "$ROOT" show "00e79124af6b77459e79fd8f47437ac2c2eaf1dc:public/javascript/telefonia_shell.js" \
  > "$SITE/public/javascript/telefonia_shell.js"
php -r '
$f=$argv[1]; $s=file_get_contents($f);
$old="    const callHelp = document.querySelector(";
if (!str_contains($s,$old)) exit(2);
$s=str_replace($old, "    const customHelp = document.querySelector(", $s);
file_put_contents($f,$s);
' "$SITE/public/javascript/telefonia_shell.js"
BEFORE="$(sha256sum "$SITE/public/javascript/telefonia_shell.js")"
if deploy --apply > "$TMP/conflict.out" 2>&1; then
  echo "ERROR: se aceptó una personalización incompatible." >&2
  exit 1
fi
if ! grep -Eq 'CONFLICTO|ERROR' "$TMP/conflict.out"; then
  echo "La publicación se detuvo sin clasificar el error de un shell desconocido:" >&2
  cat "$TMP/conflict.out" >&2
  exit 1
fi
[[ "$BEFORE" == "$(sha256sum "$SITE/public/javascript/telefonia_shell.js")" ]]
# Caso de producción: Hostinger ya publicó el modal previo y el puente
# Zadarma de 39f306f. Asegurar que --check y --apply no lo rechacen.
for p in $FILES public/javascript/telefonia_entrantes_bridge.js; do
  git -C "$ROOT" show "39f306fd998de860a515df9222700669b2d96dab:$p" > "$SITE/$p"
done
deploy --check
deploy --apply
grep -Fq 'persistent-phone-panel impe-incoming-panel' "$SITE/app/views/telefonia/shell.php"
grep -Fq 'sdk[method].apply(sdk, args || [])' "$SITE/public/javascript/telefonia_entrantes_bridge.js"
grep -Fq "answer: () => perform('contestar', 'answer'" "$SITE/public/javascript/telefonia_entrantes_bridge.js"
grep -Fq "hangup: () => perform('colgar', 'finishCall'" "$SITE/public/javascript/telefonia_entrantes_bridge.js"
deploy --apply
# Reproducir las cinco versiones del puente que pudieron quedar en Hostinger.
# La publicada al aparecer el error fue una versión posterior a 39f306f.
for rev in \
  81c9a2dd04b10e71678311e92249a327ed62fd2f \
  1e7e44d072c5d896af7ec9985d960443450afbab \
  39f306fd998de860a515df9222700669b2d96dab \
  ab0ac522a0d4de87c75c91693b42aed99f129d92 \
  4d58c860767de76b7883146a545bcbe55989b419; do
  git -C "$ROOT" show "$rev:public/javascript/telefonia_entrantes_bridge.js" \
    > "$SITE/public/javascript/telefonia_entrantes_bridge.js"
  BEFORE="$(sha256sum "$SITE/public/javascript/telefonia_entrantes_bridge.js")"
  deploy --check
  [[ "$BEFORE" == "$(sha256sum "$SITE/public/javascript/telefonia_entrantes_bridge.js")" ]]
  deploy --apply
  cmp -s "$SITE/public/javascript/telefonia_entrantes_bridge.js" \
    "$ROOT/public/javascript/telefonia_entrantes_bridge.js"
done
# Una personalización REAL desconocida no debe ser reemplazada ni ignorada.
printf '%s\n' '/* PUENTE PRIVADO MODIFICADO SIN HISTORIAL */' \
  > "$SITE/public/javascript/telefonia_entrantes_bridge.js"
BEFORE="$(sha256sum "$SITE/public/javascript/telefonia_entrantes_bridge.js")"
if deploy --apply > "$TMP/bridge-unknown.out" 2>&1; then
  echo "ERROR: el publicador sobrescribió un puente personalizado desconocido." >&2
  exit 1
fi
grep -Fq 'CONFLICTO: puente Zadarma' "$TMP/bridge-unknown.out"
[[ "$BEFORE" == "$(sha256sum "$SITE/public/javascript/telefonia_entrantes_bridge.js")" ]]
# El despliegue real de Hostinger ya tiene la versión que permitía
# contestar, anterior al reinicio del reloj (cf1c), no la base 39f3.
for rev in \
  cf1c1644baa2ed4275759555db3814b96fe06f93 \
  8047ff5d6fab58d77732daaa27ff5fa05f63511b \
  eca48644040c347766a71d802e763d46c6bfc88e \
  ab0ac522a0d4de87c75c91693b42aed99f129d92 \
  4d58c860767de76b7883146a545bcbe55989b419 \
  a63a9ebf2097b2a447335da75f4b81219b8c6c34; do
  git -C "$ROOT" show "$rev:public/javascript/telefonia_shell.js" \
    > "$SITE/public/javascript/telefonia_shell.js"
  # El puente de la prueba anterior era deliberadamente desconocido;
  # restaurarlo al contenido vigente para aislar el test de shell.
  cp "$ROOT/public/javascript/telefonia_entrantes_bridge.js" \
    "$SITE/public/javascript/telefonia_entrantes_bridge.js"
  BEFORE="$(sha256sum "$SITE/public/javascript/telefonia_shell.js")"
  deploy --check
  [[ "$BEFORE" == "$(sha256sum "$SITE/public/javascript/telefonia_shell.js")" ]]
  deploy --apply
  cmp -s "$SITE/public/javascript/telefonia_shell.js" \
    "$ROOT/public/javascript/telefonia_shell.js"
done
# Caso REAL reportado por Hostinger: shell 8047ff5 menos la única
# línea del aviso elevado. Debe tener exactamente el SHA diagnosticado.
git -C "$ROOT" show \
  "8047ff5d6fab58d77732daaa27ff5fa05f63511b:public/javascript/telefonia_shell.js" \
  > "$SITE/public/javascript/telefonia_shell.js"
sed -i \
  -e '/El aviso de conexión y "Mostrar llamada" son ambos flotantes:/d' \
  -e '/nunca apilarlos uno encima del otro./d' \
  -e "/status\\.classList\\.toggle('is-raised', !restore\\.hidden);/d" \
  "$SITE/public/javascript/telefonia_shell.js"
[[ "$(git -C "$ROOT" hash-object "$SITE/public/javascript/telefonia_shell.js")" == \
  "45e80cccb3e104917aee74122b21ebf8a68498cf" ]] || {
    echo "ERROR: la versión simulada no coincide con el hash real de Hostinger." >&2
    exit 1
  }
OLD_HASH="$(sha256sum "$SITE/public/javascript/telefonia_shell.js")"
deploy --check
[[ "$OLD_HASH" == "$(sha256sum "$SITE/public/javascript/telefonia_shell.js")" ]]
deploy --apply
grep -Fq 'pendingIncomingAction' "$SITE/public/javascript/telefonia_shell.js"
grep -Fq 'updateIncomingTimer(phoneState)' \
  "$SITE/public/javascript/telefonia_shell.js"
if grep -Fq "status.classList.toggle('is-raised', !restore.hidden);" \
  "$SITE/public/javascript/telefonia_shell.js"; then
  echo "ERROR: el despliegue no conservó el ajuste local del aviso." >&2
  exit 1
fi
deploy --check
# Cambio local independiente: preservar el ajuste al actualizar el shell.
git -C "$ROOT" show "cf1c1644baa2ed4275759555db3814b96fe06f93:public/javascript/telefonia_shell.js" \
  > "$SITE/public/javascript/telefonia_shell.js"
printf '\n// AJUSTE-LOCAL-SHELL-PRESERVAR\n' \
  >> "$SITE/public/javascript/telefonia_shell.js"
deploy --check
deploy --apply
grep -Fq 'AJUSTE-LOCAL-SHELL-PRESERVAR' \
  "$SITE/public/javascript/telefonia_shell.js"
# Cambios incompatibles en un bloque tocado por Git deben abortar sin
# alterar la versión de producción.
git -C "$ROOT" show "cf1c1644baa2ed4275759555db3814b96fe06f93:public/javascript/telefonia_shell.js" \
  > "$SITE/public/javascript/telefonia_shell.js"
php -r '
$p=$argv[1]; $s=file_get_contents($p);
$needle="    const callHelp = document.querySelector(";
if (!str_contains($s,$needle)) exit(2);
$s=str_replace($needle,"    const customHelp = document.querySelector(",$s);
file_put_contents($p,$s);
' "$SITE/public/javascript/telefonia_shell.js"
BEFORE="$(sha256sum "$SITE/public/javascript/telefonia_shell.js")"
if deploy --apply > "$TMP/shell-unknown.out" 2>&1; then
  echo "ERROR: se publicó shell incompatible." >&2
  exit 1
fi
grep -Fq 'CONFLICTO:' "$TMP/shell-unknown.out" || {
  echo "El rechazo seguro del shell debe describirse como conflicto:" >&2
  cat "$TMP/shell-unknown.out" >&2
  exit 1
}
[[ "$BEFORE" == "$(sha256sum "$SITE/public/javascript/telefonia_shell.js")" ]]
echo "Modal Marketing Hostinger: OK (puentes, seis shells históricos, ajustes locales y conflictos)"

