#!/usr/bin/env bash
# Transferencia desde recepción por estado/responsable; Analista y Asesor
# sólo a su Cuenta Clave. Publica código, NUNCA modifica la BD ni PBX.
# Ejecutar --check antes de --apply; el respaldo conserva los archivos.
set -euo pipefail
umask 077
MODE="${1:---check}"
if [[ "$MODE" != "--check" && "$MODE" != "--apply" ]]; then
  echo "Uso: bash $0 --check|--apply" >&2
  exit 2
fi
SOURCE="${IMPE_TRANSFER_SOURCE:-$HOME/crm-preparacion}"
LIVE="${IMPE_TRANSFER_PUBLIC:-$HOME/domains/fundacionrededumex.org/public_html}"
BACKUPS="${IMPE_TRANSFER_BACKUPS:-$HOME/respaldos-crm}"
BASE="74c7f5e81432f743b7890c2134f70aa3c3e5fb91"
TARGET="bd95899c318162fb514e5bfe32e57abee1d289a0"
OLD=(
  app/controllers/TelefoniaController.php
  app/controllers/TerritorioController.php
  app/models/TerritorioModel.php
  app/services/TelefoniaEntrantesAnalistaService.php
  app/views/telefonia/host.php
  app/views/telefonia/shell.php
  app/views/layout/dashboard_head.php
  app/views/territorios/equipo.php
  app/views/seguimiento_vinculacion/llamadas_entrantes.php
  public/javascript/telefonia_host.js
  public/javascript/telefonia_shell.js
  public/javascript/telefonia_persistente.js
  public/javascript/seguimiento_entrantes_analista.js
)
NEW=(
  app/services/TelefoniaEnrutamientoService.php
  public/javascript/telefonia_enrutamiento.js
  public/css/telefonia_enrutamiento.css
)
[[ -d "$SOURCE/.git" && -f "$LIVE/index.php" && ! -L "$LIVE" ]] || {
  echo "ERROR: no se encontró el repositorio o el sitio Hostinger." >&2
  exit 1
}
for rev in "$BASE" "$TARGET"; do
  git -C "$SOURCE" cat-file -e "$rev^{commit}" || {
    echo "ERROR: falta la versión del parche. Actualiza crm-preparacion con Git." >&2
    exit 1
  }
done
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
mkdir -p "$TMP/stage"
declare -a CHANGED=()
declare -a CREATED=()
declare -A SHA=()
for i in "${!OLD[@]}"; do
  file="${OLD[$i]}"
  [[ -f "$LIVE/$file" && ! -L "$LIVE/$file" ]] || {
    echo "ERROR: falta archivo publicado $file" >&2; exit 1
  }
  SHA["$file"]="$(sha256sum "$LIVE/$file" | cut -d' ' -f1)"
  mkdir -p "$TMP/stage/$(dirname "$file")"
  cp -p "$LIVE/$file" "$TMP/stage/$file"
  patch="$TMP/p_$i.patch"
  git -C "$SOURCE" diff --binary "$BASE" "$TARGET" -- "$file" > "$patch"
  [[ -s "$patch" ]] || { echo "ERROR: parche vacío $file" >&2; exit 1; }
  if (cd "$TMP/stage" && git apply --reverse --check "$patch" >/dev/null 2>&1); then
    echo "YA ACTUALIZADO: $file"
  elif (cd "$TMP/stage" && git apply --check "$patch" >/dev/null 2>&1); then
    (cd "$TMP/stage" && git apply "$patch")
    CHANGED+=("$file")
    echo "PARCHE COMPATIBLE: $file"
  else
    echo "CONFLICTO: $file tiene cambios fuera del parche. PRODUCCIÓN INTACTA." >&2
    exit 1
  fi
done
for file in "${NEW[@]}"; do
  mkdir -p "$TMP/stage/$(dirname "$file")"
  git -C "$SOURCE" show "$TARGET:$file" > "$TMP/stage/$file" || {
    echo "ERROR: falta $file en la versión revisada" >&2; exit 1;
  }
  if [[ -e "$LIVE/$file" ]]; then
    [[ -f "$LIVE/$file" && ! -L "$LIVE/$file" ]] &&
    cmp -s "$LIVE/$file" "$TMP/stage/$file" || {
      echo "CONFLICTO: existe $file con cambios desconocidos." >&2
      exit 1
    }
    echo "YA ACTUALIZADO: $file"
  else
    CREATED+=("$file")
    echo "ARCHIVO NUEVO COMPATIBLE: $file"
  fi
done
for file in "${OLD[@]}" "${NEW[@]}"; do
  case "$file" in
    *.php) php -l "$TMP/stage/$file" >/dev/null ;;
    *.js) if command -v node >/dev/null 2>&1; then
        node --check "$TMP/stage/$file" >/dev/null
      fi ;;
  esac
done
# Guardas de seguridad: sin teléfono duplicado, sin sesión API pública,
# sin libertad para transferir a cualquier extensión.
grep -Fq "autorizarTransferencia" "$TMP/stage/app/controllers/TelefoniaController.php"
grep -Fq "validarOrdenTransferencia" "$TMP/stage/app/controllers/TelefoniaController.php"
grep -Fq "cuenta_clave_asignacion_id = a.id" \
  "$TMP/stage/app/services/TelefoniaEnrutamientoService.php"
grep -Fq "validarTransferenciaUrl" "$TMP/stage/app/views/telefonia/host.php"
grep -Fq "ejecutarTransferenciaAutorizada" "$TMP/stage/public/javascript/telefonia_host.js"
grep -Fq "data-shell-transfer-panel" "$TMP/stage/app/views/telefonia/shell.php"
grep -Fq "IMPE_ANALYST_WEBRTC_PASSIVE_JOURNAL" "$TMP/stage/app/views/telefonia/shell.php"
grep -Fq "data-incoming-register-finished" "$TMP/stage/app/views/telefonia/shell.php"
grep -Fq "impeShellNavigation" "$TMP/stage/app/views/telefonia/shell.php"
grep -Fq "impeDashboardNativeNavigation" "$TMP/stage/app/views/layout/dashboard_head.php"

if [[ "${#CHANGED[@]}" -eq 0 && "${#CREATED[@]}" -eq 0 ]]; then
  echo "OK: directorio y captura ya publicados."
  exit 0
fi
if [[ "$MODE" == "--check" ]]; then
  echo "OK: transferencia territorial compatible. PRODUCCIÓN INTACTA."
  echo "Sin cambios en MySQL, extensiones PBX, credenciales ni grabaciones."
  exit 0
fi
for file in "${CHANGED[@]}"; do
  [[ "$(sha256sum "$LIVE/$file" | cut -d' ' -f1)" == "${SHA[$file]}" ]] || {
    echo "CONFLICTO: cambió $file tras el preflight." >&2; exit 1
  }
done
for file in "${CREATED[@]}"; do
  [[ ! -e "$LIVE/$file" ]] || {
    echo "CONFLICTO: apareció $file durante el preflight." >&2; exit 1;
  }
done
mkdir -p "$BACKUPS"
BACKUP="$BACKUPS/transferencias-seguras-$(date +%Y%m%d-%H%M%S)-$$"
mkdir -p "$BACKUP"
for file in "${CHANGED[@]}"; do
  mkdir -p "$BACKUP/$(dirname "$file")"
  cp -p "$LIVE/$file" "$BACKUP/$file"
done
rollback() {
  code=$?
  trap - EXIT
  if [[ "$code" -ne 0 ]]; then
    for file in "${CHANGED[@]}"; do cp -p "$BACKUP/$file" "$LIVE/$file"; done
    for file in "${CREATED[@]}"; do rm -f "$LIVE/$file"; done
    echo "ERROR: restaurados respaldos desde $BACKUP." >&2
  fi
  exit "$code"
}
trap rollback EXIT
for file in "${CREATED[@]}"; do
  cp -p "$TMP/stage/$file" "$LIVE/$file"
  chmod 0644 "$LIVE/$file"
  cmp -s "$TMP/stage/$file" "$LIVE/$file"
done
for file in "${CHANGED[@]}"; do
  cp -p "$TMP/stage/$file" "$LIVE/$file"
  chmod 0644 "$LIVE/$file"
  cmp -s "$TMP/stage/$file" "$LIVE/$file"
done
php -l "$LIVE/app/controllers/TelefoniaController.php" >/dev/null
php -l "$LIVE/app/views/telefonia/shell.php" >/dev/null
php -l "$LIVE/app/models/TerritorioModel.php" >/dev/null
trap - EXIT
echo "OK: transferencia contextual y captura publicadas. Respaldo: $BACKUP"
echo "Verificar la transferencia real en una llamada interna controlada."
echo "NO asumir confirmación PBX por haber enviado una orden DTMF."
