#!/usr/bin/env bash
# Diagnóstico sin modificaciones del shell de telefonía instalado en Hostinger.
set -euo pipefail
MODE="${1:---check}"
[[ "$MODE" == "--check" || "$MODE" == "--diff" ]] || {
  echo "Uso: bash tools/diagnosticar_shell_telefonia_hostinger.sh [--check|--diff]" >&2
  exit 2
}
SRC="${IMPE_SHELL_SOURCE_DIR:-$HOME/crm-preparacion}"
DST="${IMPE_SHELL_PUBLIC_DIR:-$HOME/domains/fundacionrededumex.org/public_html}"
P="public/javascript/telefonia_shell.js"
BASE="cf1c1644baa2ed4275759555db3814b96fe06f93"
[[ -d "$SRC/.git" && -f "$SRC/$P" && -f "$DST/$P" ]] || {
  echo "ERROR: falta el repositorio, el shell GitHub o el shell público." >&2
  exit 2
}
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
git -C "$SRC" show "$BASE:$P" > "$TMP/base"
echo "Diagnóstico WebRTC (solo lectura; no publica archivos)"
echo "Versión de producción: $(git -C "$SRC" hash-object "$DST/$P")"
echo "Versión de GitHub:     $(git -C "$SRC" hash-object "$SRC/$P")"
echo "Base histórica:        $(git -C "$SRC" hash-object "$TMP/base")"
echo
printf '%-28s | %-10s | %-10s | %-10s\n' "Verificación" "Hostinger" "GitHub" "Fusión"
if git merge-file -p -L HOSTINGER -L BASE -L GITHUB \
  "$DST/$P" "$TMP/base" "$SRC/$P" > "$TMP/fusion"; then
  MERGE_STATUS="Sin conflicto textual"
else
  MERGE_STATUS="Conflicto textual"
fi
check() {
  local title="$1" needle="$2"
  local live="FALTA" upstream="FALTA" merged="FALTA"
  grep -Fq "$needle" "$DST/$P" && live="OK"
  grep -Fq "$needle" "$SRC/$P" && upstream="OK"
  grep -Fq "$needle" "$TMP/fusion" && merged="OK"
  printf '%-28s | %-10s | %-10s | %-10s\n' "$title" "$live" "$upstream" "$merged"
}
check "Ayuda / selector" "const callHelp = document.querySelector('[data-shell-call-help]');"
check "Contestar SDK" 'providerBridge()?.answer()'
check "Modal entrante" 'const customVisible = incoming'
check "Estado acciones recientes" 'pendingIncomingAction'
check "Cronómetro entrante" "incomingTimer.textContent = ringing ? '00:00'"
check "Colgar SDK" 'providerBridge()?.hangup()'
echo
echo "Resultado de fusión: $MERGE_STATUS"
if cmp -s "$TMP/base" "$SRC/$P"; then
  echo "Nota: la base histórica y GitHub son iguales para este archivo;"
  echo "una fusión textual no incorpora nuevas funciones por sí misma."
fi
echo "No se alteró ningún archivo."
if [[ "$MODE" == "--diff" ]]; then
  echo
  echo "Diferencias Hostinger frente a la versión Git conocida:"
  echo "Revisa las líneas antes de compartirlas si contienen información privada."
  git diff --no-index --unified=3 -- "$TMP/base" "$DST/$P" \
    | sed -n '1,180p' || true
fi
