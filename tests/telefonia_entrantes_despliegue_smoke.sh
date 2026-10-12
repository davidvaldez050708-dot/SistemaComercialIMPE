#!/usr/bin/env bash
# Ensayo íntegramente aislado: no modifica producción.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
LIVE="$TMP/domains/fundacionrededumex.org/public_html"
mkdir -p "$LIVE/app/views/telefonia" "$LIVE/public/css" "$LIVE/public/javascript"
touch "$LIVE/index.php"
ln -s "$ROOT" "$TMP/crm-preparacion"
git -C "$ROOT" cat-file blob 361172edf4a61c884cdfa60135152f6b84590759 > "$LIVE/public/css/telefonia_host.css"
git -C "$ROOT" cat-file blob 1590a267840e6ac5d341254fdb760d4deb04b4b7 > "$LIVE/public/javascript/telefonia_host.js"
git -C "$ROOT" cat-file blob 13cdd5876bedf6313168c53c13666fe602ebe89a > "$LIVE/public/css/telefonia_shell.css"
git -C "$ROOT" cat-file blob 2352c5aa9a1e2c2cb9329cfba79a785c83b5792e > "$LIVE/public/javascript/telefonia_shell.js"
git -C "$ROOT" cat-file blob 94d7e74fb8a1dca3e5afd45e44db48a46be9dfce > "$LIVE/public/javascript/telefonia_persistente.js"
git -C "$ROOT" cat-file blob 763470485ce10bd7a74ec2f938eca3bae0877e31 > "$LIVE/app/views/telefonia/shell.php"
echo '// NOTA-LOCAL-EN-HOSTINGER' >> "$LIVE/public/javascript/telefonia_shell.js"
echo '/* TEMA-LOCAL */' >> "$LIVE/public/css/telefonia_shell.css"
echo '<!-- NOTA-LOCAL -->' >> "$LIVE/app/views/telefonia/shell.php"
deploy() { HOME="$TMP" bash "$ROOT/tools/desplegar_telefonia_entrantes_hostinger.sh"; }
deploy
grep -Fq 'El SDK conserva sus propios botones' "$LIVE/public/javascript/telefonia_host.js"
grep -Fq 'NOTA-LOCAL-EN-HOSTINGER' "$LIVE/public/javascript/telefonia_shell.js"
grep -Fq 'TEMA-LOCAL' "$LIVE/public/css/telefonia_shell.css"
grep -Fq 'NOTA-LOCAL' "$LIVE/app/views/telefonia/shell.php"
grep -Fq 'data-shell-call-help' "$LIVE/app/views/telefonia/shell.php"
OLD="$(sha256sum "$LIVE/public/javascript/telefonia_host.js")"
deploy
[[ "$OLD" == "$(sha256sum "$LIVE/public/javascript/telefonia_host.js")" ]]
[[ "$(find "$TMP/respaldos-crm" -type f | wc -l)" -eq 6 ]]
# Un cambio local en un bloque eliminado por la corrección debe provocar conflicto.
git -C "$ROOT" cat-file blob 1590a267840e6ac5d341254fdb760d4deb04b4b7 > "$LIVE/public/javascript/telefonia_host.js"
php -r '
$f=$argv[1]; $s=file_get_contents($f);
$s=str_replace("const normalizarTextoNativo = function (valor) {",
 "const reemplazoLocal = function (valor) {", $s, $n);
if ($n !== 1) exit(2);
file_put_contents($f,$s);
' "$LIVE/public/javascript/telefonia_host.js"
OLD="$(sha256sum "$LIVE/public/javascript/telefonia_host.js")"
if deploy > "$TMP/conflict.txt" 2>&1; then
  echo "ERROR: despliegue permitió conflicto." >&2
  exit 1
fi
grep -Fq 'CONFLICTO' "$TMP/conflict.txt"
[[ "$OLD" == "$(sha256sum "$LIVE/public/javascript/telefonia_host.js")" ]]
echo "Telefonía entrante Hostinger: OK (fusión, repetición y aborto seguro)"
