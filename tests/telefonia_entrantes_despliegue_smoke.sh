#!/usr/bin/env bash
# Ensayo íntegramente aislado: no modifica producción.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
LIVE="$TMP/domains/fundacionrededumex.org/public_html"
mkdir -p "$LIVE/app/views/telefonia" "$LIVE/public/css" "$LIVE/public/javascript"
touch "$LIVE/index.php"
# Esta prueba cubre el despliegue legacy del 12/oct; usar su versión
# histórica como ORIGEN. El shell actual ya tiene nuevos cambios de
# navegación/recepción y no debe forzarse por el instalador antiguo.
git -C "$ROOT" worktree add --detach "$TMP/historico" 6bd2b5478447 >/dev/null
ln -s "$TMP/historico" "$TMP/crm-preparacion"
git -C "$ROOT" cat-file blob 361172edf4a61c884cdfa60135152f6b84590759 > "$LIVE/public/css/telefonia_host.css"
git -C "$ROOT" cat-file blob 1590a267840e6ac5d341254fdb760d4deb04b4b7 > "$LIVE/public/javascript/telefonia_host.js"
git -C "$ROOT" cat-file blob 13cdd5876bedf6313168c53c13666fe602ebe89a > "$LIVE/public/css/telefonia_shell.css"
git -C "$ROOT" cat-file blob 2352c5aa9a1e2c2cb9329cfba79a785c83b5792e > "$LIVE/public/javascript/telefonia_shell.js"
git -C "$ROOT" cat-file blob 94d7e74fb8a1dca3e5afd45e44db48a46be9dfce > "$LIVE/public/javascript/telefonia_persistente.js"
git -C "$ROOT" cat-file blob 763470485ce10bd7a74ec2f938eca3bae0877e31 > "$LIVE/app/views/telefonia/shell.php"
echo '// NOTA-LOCAL-EN-HOSTINGER' >> "$LIVE/public/javascript/telefonia_shell.js"
echo '/* TEMA-LOCAL */' >> "$LIVE/public/css/telefonia_shell.css"
echo '<!-- NOTA-LOCAL -->' >> "$LIVE/app/views/telefonia/shell.php"
deploy() { HOME="$TMP" bash "$TMP/historico/tools/desplegar_telefonia_entrantes_hostinger.sh"; }
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
# Escenario REAL informado: Hostinger utiliza el bloque 1x1 incondicional
# y el centinela con z-index extremo. Debe normalizarse sin perder
# personalizaciones ajenas a dicho bloque.
git -C "$ROOT" cat-file blob 361172edf4a61c884cdfa60135152f6b84590759 > "$LIVE/public/css/telefonia_host.css"
git -C "$ROOT" cat-file blob 1590a267840e6ac5d341254fdb760d4deb04b4b7 > "$LIVE/public/javascript/telefonia_host.js"
php -r '
$f = $argv[1]; $css = file_get_contents($f);
$first = strpos($css, "/*\n * El SDK de Zadarma debe seguir vivo para WebRTC");
$last = strpos($css, "/* Modo opcional de pestaña:", $first);
if ($first === false || $last === false) exit(2);
$legacy = <<<CSS
/*
 * El SDK de Zadarma debe seguir vivo para WebRTC, pero su marcador nativo
 * nunca debe convertirse en una segunda interfaz del CRM.
 */
body > *:not(.telephony-host-sentinel):not(.telephony-host-tab-info):not(script) {
    position: fixed !important;
    left: -10000px !important;
    top: -10000px !important;
    right: auto !important;
    bottom: auto !important;
    width: 1px !important;
    height: 1px !important;
    max-width: 1px !important;
    max-height: 1px !important;
    overflow: hidden !important;
    opacity: 0 !important;
    pointer-events: none !important;
}

.telephony-host-sentinel {
    z-index: 2147483647;
}
CSS;
$css = substr($css, 0, $first) . $legacy . "\n\n" . substr($css, $last);
$first = strpos($css, "/* El propio SDK conserva sus dimensiones");
$last = strpos($css, "/* WebRTC embebido:", $first);
if ($first === false || $last === false) exit(3);
$css = substr($css, 0, $first) . substr($css, $last);
$css .= "\n/* CLIENTE-CSS-PERSONALIZADO-DEBE-CONSERVARSE */\n";
file_put_contents($f, $css);
' "$LIVE/public/css/telefonia_host.css"
# El resto ya está publicado: el nuevo ciclo debe actualizar sólo el CSS legado.
deploy
grep -Fq 'CLIENTE-CSS-PERSONALIZADO-DEBE-CONSERVARSE' "$LIVE/public/css/telefonia_host.css"
grep -Fq 'El iframe aislado del shell oculta al proveedor' "$LIVE/public/css/telefonia_host.css"
grep -Fq 'body.telephony-host-incoming { overflow: hidden; }' "$LIVE/public/css/telefonia_host.css"
if grep -Fq 'body > *:not(.telephony-host-sentinel)' "$LIVE/public/css/telefonia_host.css"; then
  echo "ERROR: todavía se fuerza el widget a 1x1." >&2
  exit 1
fi
deploy
# Variante con reglas 1x1 locales distintas: abortar sin tocar producción.
git -C "$ROOT" cat-file blob 361172edf4a61c884cdfa60135152f6b84590759 > "$LIVE/public/css/telefonia_host.css"
php -r '
$f = $argv[1]; $css = file_get_contents($f);
$css = str_replace("body:not(.telephony-host-incoming) > *",
                   "body > *", $css);
file_put_contents($f, $css);
' "$LIVE/public/css/telefonia_host.css"
# El CSS desconocido no se normaliza inventando cambios. Debe abortar
# aunque el resto de archivos ya esté actualizado.
OLD="$(sha256sum "$LIVE/public/css/telefonia_host.css")"
if deploy > "$TMP/css-desconocido.out" 2>&1; then
  echo "ERROR: el despliegue aceptó reglas 1x1 desconocidas" >&2
  exit 1
fi
grep -Fq 'CONFLICTO' "$TMP/css-desconocido.out"
[[ "$OLD" == "$(sha256sum "$LIVE/public/css/telefonia_host.css")" ]]
echo "Telefonía entrante Hostinger: OK (fusión, repetición, conflicto y CSS legado)"

