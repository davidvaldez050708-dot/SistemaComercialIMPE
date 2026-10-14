#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
ROOT="$PWD"
SCRIPT="tools/desplegar_estabilidad_shell_analista_hostinger.sh"
NAV="public/javascript/telefonia_shell_navegacion.js"
FILES=("app/views/telefonia/shell.php" "public/javascript/telefonia_shell.js" "app/controllers/TelefoniaController.php" "public/css/telefonia_shell.css")
BASES=(310fa2f2e4917583e8177456fed5ed909913dd6f 6dc7b7fad51e5685bd63e1f13ac4f9a5cee7bb8f 59f35c3ae14ebd20456d6b19f88baba6712a355e 639992293946becae46c390daf0cb3184d392bfc)
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
SITE="$TMP/site"
for i in "${!FILES[@]}"; do
  f="${FILES[$i]}"
  mkdir -p "$SITE/$(dirname "$f")"
  git show "${BASES[$i]}:$f" > "$SITE/$f"
done
CSS="${FILES[3]}"
python3 - "$SITE/$CSS" <<'PY'
import pathlib,sys
p=pathlib.Path(sys.argv[1])
css=p.read_text()
old='color: #52698c; font: 600 11px'
assert css.count(old)==1
css=css.replace(old,'color: #485f79; font: 600 11px')
css+='/* Ajuste del visor de llamadas publicado en Hostinger. */\n'
css+='.impe-incoming-panel.is-visible { max-width: min(96vw, 460px); }\n'
p.write_text(css)
PY
cp "$SITE/$CSS" "$TMP/original.css"
export IMPE_SHELL_ESTABLE_SOURCE="$ROOT"
export IMPE_SHELL_ESTABLE_PUBLIC="$SITE"
export IMPE_SHELL_ESTABLE_BACKUPS="$TMP/backups"
bash -n "$SCRIPT"
bash "$SCRIPT" --check > "$TMP/check"
grep -Fq 'CSS personalizado conservado' "$TMP/check"
grep -Fq 'PRODUCCIÓN INTACTA' "$TMP/check"
cmp -s "$SITE/$CSS" "$TMP/original.css"
[[ ! -e "$SITE/$NAV" ]]
bash "$SCRIPT" --apply >"$TMP/apply"
grep -Fq 'shell telefónico estable publicado' "$TMP/apply"
grep -Fq 'Ajuste del visor de llamadas publicado' "$SITE/$CSS"
grep -Fq '#485f79' "$SITE/$CSS"
grep -Fq '.impe-shell-navigation-mask' "$SITE/$CSS"
grep -Fq '.impe-shell-navigation-progress' "$SITE/$CSS"
cmp -s "$NAV" "$SITE/$NAV"
[[ -n "$(find "$TMP/backups" -type f)" ]]
cp "$SITE/$CSS" "$TMP/published.css"
bash "$SCRIPT" --check | grep -Fq 'ya publicada'
bash "$SCRIPT" --apply | grep -Fq 'ya publicada'
cmp -s "$SITE/$CSS" "$TMP/published.css"
PARTIAL="$TMP/partial"
for i in "${!FILES[@]}"; do
  f="${FILES[$i]}"
  mkdir -p "$PARTIAL/$(dirname "$f")"
  git show "${BASES[$i]}:$f" >"$PARTIAL/$f"
done
printf '\n.impe-shell-navigation-mask { background: red; }\n' >> "$PARTIAL/$CSS"
cp "$PARTIAL/$CSS" "$TMP/partial-before.css"
export IMPE_SHELL_ESTABLE_PUBLIC="$PARTIAL"
if bash "$SCRIPT" --check >"$TMP/rejected" 2>&1; then
  echo "ERROR: permitió CSS de navegación previo desconocido." >&2
  exit 1
fi
grep -Fq "CONFLICTO:" "$TMP/rejected"
cmp -s "$PARTIAL/$CSS" "$TMP/partial-before.css"
[[ ! -e "$PARTIAL/$NAV" ]]
echo "Shell CSS Hostinger: OK (estilos conservados, solo agregados, idempotencia y duplicados)."
