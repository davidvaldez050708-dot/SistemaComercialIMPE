#!/usr/bin/env bash
# Eliminar pantalla de carga entre módulos sin modificar PBX ni WebRTC.
# Publicación selectiva, verificación previa y respaldos reversibles.
set -euo pipefail
umask 077
MODE="${1:---check}"
[[ "$MODE" == "--check" || "$MODE" == "--apply" ]] || {
  echo "Uso: bash $0 --check|--apply" >&2
  exit 2
}
SOURCE="${IMPE_NAV_NATIVA_SOURCE:-$HOME/crm-preparacion}"
LIVE="${IMPE_NAV_NATIVA_PUBLIC:-$HOME/domains/fundacionrededumex.org/public_html}"
BACKUPS="${IMPE_NAV_NATIVA_BACKUPS:-$HOME/respaldos-crm}"
FILES=(
  "public/javascript/telefonia_shell_navegacion.js"
  "app/views/telefonia/shell.php"
  "app/views/layout/dashboard_head.php"
)
BASE=(
  "2b98075df90d7d8f2cc5386a9044e09c936667ee"
  "76b21195687998d5bc42da5f29052ce237265a4b"
  "659781c06a236c81ed84ebc4ac6b61c964ccfb17"
)
TARGET=(
  "76b21195687998d5bc42da5f29052ce237265a4b"
  "659781c06a236c81ed84ebc4ac6b61c964ccfb17"
  "377d018b08366d4f0393637105316c2ad6c8a73e"
)
[[ -d "$SOURCE/.git" && -f "$LIVE/index.php" && ! -L "$LIVE" ]] || {
  echo "ERROR: clon Git o CRM de Hostinger no disponibles." >&2
  exit 1
}
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
mkdir -p "$TMP/stage"
declare -A HASH=()
declare -a CAMBIADOS=()
for i in "${!FILES[@]}"; do
  file="${FILES[$i]}"
  [[ -f "$LIVE/$file" && ! -L "$LIVE/$file" ]] || {
    echo "ERROR: archivo no encontrado o no regular: $file" >&2
    exit 1
  }
  for revision in "${BASE[$i]}" "${TARGET[$i]}"; do
    git -C "$SOURCE" cat-file -e "$revision^{commit}" || {
      echo "ERROR: falta revisión. Ejecuta git pull --ff-only origin main." >&2
      exit 1
    }
  done
  HASH["$file"]="$(sha256sum "$LIVE/$file" | cut -d' ' -f1)"
  mkdir -p "$TMP/stage/$(dirname "$file")"
  cp -p "$LIVE/$file" "$TMP/stage/$file"
  patch="$TMP/patch_$i"
  git -C "$SOURCE" diff --binary "${BASE[$i]}" "${TARGET[$i]}" -- "$file" > "$patch"
  [[ -s "$patch" ]] || { echo "ERROR: parche vacío: $file" >&2; exit 1; }
  if (cd "$TMP/stage" && git apply --reverse --check "$patch" >/dev/null 2>&1); then
    echo "YA ACTUALIZADO: $file"
  elif (cd "$TMP/stage" && git apply --check "$patch" >/dev/null 2>&1); then
    (cd "$TMP/stage" && git apply "$patch")
    CAMBIADOS+=("$file")
    echo "PARCHE COMPATIBLE: $file"
  elif [[ "$file" == "app/views/layout/dashboard_head.php" ]]; then
    # Respetar ajustes anteriores del layout en Hostinger.
    git -C "$SOURCE" show "${BASE[$i]}:$file" > "$TMP/head_before"
    git -C "$SOURCE" show "${TARGET[$i]}:$file" > "$TMP/head_after"
    if git merge-file -p "$TMP/stage/$file" "$TMP/head_before" "$TMP/head_after" > "$TMP/merged"; then
      if ! cmp -s "$TMP/stage/$file" "$TMP/merged"; then
        cp -p "$TMP/merged" "$TMP/stage/$file"
        CAMBIADOS+=("$file")
      fi
      echo "FUSIÓN COMPATIBLE: dashboard_head.php con ajustes locales conservados."
    else
      echo "CONFLICTO: dashboard_head.php tiene modificaciones en la zona del parche." >&2
      exit 1
    fi
  else
    echo "CONFLICTO: $file no corresponde a la versión publicada. PRODUCCIÓN INTACTA." >&2
    exit 1
  fi
done

NAV="$TMP/stage/public/javascript/telefonia_shell_navegacion.js"
SHELL="$TMP/stage/app/views/telefonia/shell.php"
HEAD="$TMP/stage/app/views/layout/dashboard_head.php"
php -l "$SHELL" >/dev/null
php -l "$HEAD" >/dev/null
if command -v node >/dev/null 2>&1; then node --check "$NAV"; fi
for marker in "data-shell-navigation-mask hidden" "IMPE_ANALYST_WEBRTC_PASSIVE_JOURNAL" \
  "data-incoming-register-finished" "telefonia_analista_historial_pasivo.js"; do
  grep -Fq "$marker" "$SHELL" || {
    echo "ERROR: falta $marker en shell preparado." >&2; exit 1;
  }
done
grep -Fq "const setRecovery = function" "$NAV"
grep -Fq "if (token !== navigationToken || !navigating) return;" "$NAV"
if grep -Fq "Cargando módulo" "$NAV"; then
  echo "ERROR: quedó la pantalla de carga." >&2
  exit 1
fi
grep -Fq "impeDashboardNativeNavigation" "$HEAD"
grep -Fq "@view-transition { navigation: auto; }" "$HEAD"
grep -Fq "dashboard.css?v=<?= filemtime" "$HEAD"
if grep -Fq "dashboard.css?v=<?= time()" "$HEAD"; then
  echo "ERROR: dashboard.css continúa sin caché." >&2; exit 1;
fi
if [[ "${#CAMBIADOS[@]}" -eq 0 ]]; then
  echo "OK: navegación natural ya publicada."
  exit 0
fi
if [[ "$MODE" == "--check" ]]; then
  echo "OK: navegación natural compatible. PRODUCCIÓN INTACTA."
  echo "Sólo shell, navegación JS y caché del dashboard."
  exit 0
fi
for file in "${CAMBIADOS[@]}"; do
  [[ "$(sha256sum "$LIVE/$file" | cut -d' ' -f1)" == "${HASH[$file]}" ]] || {
    echo "CONFLICTO: $file cambió durante comprobaciones." >&2; exit 1;
  }
done
mkdir -p "$BACKUPS"
BACKUP="$BACKUPS/navegacion-natural-crm-$(date +%Y%m%d-%H%M%S)-$$"
mkdir -p "$BACKUP"
for file in "${CAMBIADOS[@]}"; do
  mkdir -p "$BACKUP/$(dirname "$file")"
  cp -p "$LIVE/$file" "$BACKUP/$file"
done
rollback() {
  status=$?
  trap - EXIT
  if [[ "$status" -ne 0 ]]; then
    for file in "${CAMBIADOS[@]}"; do cp -p "$BACKUP/$file" "$LIVE/$file"; done
    echo "ERROR: respaldos restaurados desde $BACKUP" >&2
  fi
  exit "$status"
}
trap rollback EXIT
for file in "${CAMBIADOS[@]}"; do
  cp -p "$TMP/stage/$file" "$LIVE/$file"
  chmod 0644 "$LIVE/$file"
  cmp -s "$TMP/stage/$file" "$LIVE/$file"
done
php -l "$LIVE/app/views/telefonia/shell.php" >/dev/null
php -l "$LIVE/app/views/layout/dashboard_head.php" >/dev/null
trap - EXIT
echo "OK: navegación natural sin pantalla de carga publicada."
echo "Respaldo: $BACKUP"
echo "Llamadas, SDK, extensiones, historial WebRTC y MySQL sin cambios."
