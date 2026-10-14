#!/usr/bin/env bash
# Cambios selectivos para llamadas internas de Analistas de Datos.
# Evita reemplazar el resto del CRM. Con respaldo y comprobación previa.
set -euo pipefail
umask 077
MODE="${1:---check}"
if [[ "$MODE" != "--check" && "$MODE" != "--apply" ]]; then
  echo "Uso: bash $0 --check|--apply" >&2
  exit 2
fi
SOURCE="${IMPE_INTERNAS_SOURCE:-$HOME/crm-preparacion}"
LIVE="${IMPE_INTERNAS_PUBLIC:-$HOME/domains/fundacionrededumex.org/public_html}"
BACKUPS="${IMPE_INTERNAS_BACKUPS:-$HOME/respaldos-crm}"
FILES=(
  "app/services/TelefoniaAnalistaWebrtcService.php"
  "app/services/TelefoniaEntrantesAnalistaService.php"
  "app/controllers/SeguimientoVinculacionController.php"
  "app/services/ZadarmaRecordingService.php"
  "app/views/seguimiento_vinculacion/llamadas_entrantes.php"
  "public/javascript/telefonia_analista_historial_pasivo.js"
  "public/css/seguimiento_entrantes_analista.css"
  "public/javascript/seguimiento_entrantes_analista.js"
)
BASES=(
  "ee4cc0b3ce23b22e961e7baa82f8ce68315fcef0"
  "ce7d291e95cc14e13bca40e40d488d98ed2dc6ed"
  "ee1c2348231498c8a5b7b9e2a5235cf2dfa2f1b1"
  "9288c29dda42416184a87e7d00c8465004b013a0"
  "a1c80214ad954540940cba6a3704fc1cfae17d58"
  "69d55b1920884814e30a0ae34ecd90243ae99581"
  "4e41519df08c9265b042fdc39b239bb482391ebe"
  "6faf88e90813775cec3ae024e1e6dc585f2fc233"
)
TARGET="053ee9459df1e234683b730e37f5fcf3cc9292f8"
NEWFILES=(
  "app/services/TelefoniaInternaGrabacionService.php"
  "prueba_telefonia/api/grabacion_interna_analista.php"
)
[[ -d "$SOURCE/.git" && -d "$LIVE" && ! -L "$LIVE" ]] || {
  echo "ERROR: sitio o clon Git no disponible." >&2; exit 1;
}
git -C "$SOURCE" cat-file -e "${TARGET}^{commit}" || {
  echo "ERROR: ejecuta git pull --ff-only origin main." >&2; exit 1;
}
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
mkdir -p "$TMP/stage"
declare -a CAMBIADOS=()
declare -a NUEVOS=()
declare -A SHAS=()
for i in "${!FILES[@]}"; do
  file="${FILES[$i]}"
  git -C "$SOURCE" cat-file -e "${BASES[$i]}^{commit}" || {
    echo "ERROR: faltan commits para $file" >&2; exit 1;
  }
  [[ -f "$LIVE/$file" && ! -L "$LIVE/$file" ]] || {
    echo "ERROR: no está publicado el archivo: $file" >&2; exit 1;
  }
  SHAS["$file"]="$(sha256sum "$LIVE/$file" | cut -d' ' -f1)"
  mkdir -p "$TMP/stage/$(dirname "$file")"
  cp -p "$LIVE/$file" "$TMP/stage/$file"
  parche="$TMP/parche_$i.diff"
  git -C "$SOURCE" diff --binary "${BASES[$i]}" "$TARGET" -- "$file" > "$parche"
  [[ -s "$parche" ]] || { echo "ERROR: parche vacío $file" >&2; exit 1; }
  if (cd "$TMP/stage" && git apply --reverse --check "$parche" >/dev/null 2>&1); then
    echo "YA ACTUALIZADO: $file"
  elif (cd "$TMP/stage" && git apply --check "$parche" >/dev/null 2>&1); then
    (cd "$TMP/stage" && git apply "$parche")
    CAMBIADOS+=("$file")
    echo "PARCHE COMPATIBLE: $file"
  else
    echo "CONFLICTO: $file. No se modificará producción." >&2
    exit 1
  fi
done
for file in "${NEWFILES[@]}"; do
  [[ -f "$SOURCE/$file" && ! -L "$SOURCE/$file" ]] || {
    echo "ERROR: falta en Git el archivo nuevo: $file" >&2; exit 1;
  }
  mkdir -p "$TMP/stage/$(dirname "$file")"
  cp -p "$SOURCE/$file" "$TMP/stage/$file"
  if [[ -e "$LIVE/$file" ]]; then
    [[ -f "$LIVE/$file" && ! -L "$LIVE/$file" ]] || {
      echo "CONFLICTO: $file no es archivo regular." >&2; exit 1;
    }
    cmp -s "$LIVE/$file" "$TMP/stage/$file" || {
      echo "CONFLICTO: $file existe, pero difiere. Producción intacta." >&2; exit 1;
    }
    echo "YA ACTUALIZADO: $file"
  else
    NUEVOS+=("$file")
    echo "ARCHIVO NUEVO COMPATIBLE: $file"
  fi
done
for file in "${FILES[@]}" "${NEWFILES[@]}"; do
  if [[ "$file" == *.php ]]; then php -l "$TMP/stage/$file" >/dev/null; fi
done
if command -v node >/dev/null 2>&1; then
  node --check "$TMP/stage/public/javascript/telefonia_analista_historial_pasivo.js"
  node --check "$TMP/stage/public/javascript/seguimiento_entrantes_analista.js"
fi
grep -Fq "'tipo_llamada' =>" "$TMP/stage/app/services/TelefoniaAnalistaWebrtcService.php"
grep -Fq "esNumeroInterno(" "$TMP/stage/app/services/TelefoniaEntrantesAnalistaService.php"
grep -Fq "grabacion_interna_analista.php" "$TMP/stage/app/views/seguimiento_vinculacion/llamadas_entrantes.php"
grep -Fq "session_id = ?" "$TMP/stage/prueba_telefonia/api/grabacion_interna_analista.php" && {
  : # Un endpoint público sólo admite identificadores SDK de la sesión.
} || grep -Fq "sessionId = trim" "$TMP/stage/prueba_telefonia/api/grabacion_interna_analista.php"

if [[ "${#CAMBIADOS[@]}" -eq 0 && "${#NUEVOS[@]}" -eq 0 ]]; then
  echo "OK: llamadas internas y audio ya publicados."
  exit 0
fi
if [[ "$MODE" == "--check" ]]; then
  echo "OK: parches compatibles. PRODUCCIÓN INTACTA."
  echo "Sólo se modifican componentes de llamadas del Analista."
  echo "No se borran datos ni se alteran otras áreas del CRM."
  exit 0
fi
for file in "${CAMBIADOS[@]}"; do
  [[ "$(sha256sum "$LIVE/$file" | cut -d' ' -f1)" == "${SHAS[$file]}" ]] || {
    echo "CONFLICTO: $file cambió después del preflight." >&2; exit 1;
  }
done
for file in "${NUEVOS[@]}"; do
  [[ ! -e "$LIVE/$file" ]] || {
    echo "CONFLICTO: $file ya existe en la publicación." >&2; exit 1;
  }
done
mkdir -p "$BACKUPS"
BACKUP="$BACKUPS/internas-analista-$(date +%Y%m%d-%H%M%S)-$$"
mkdir -p "$BACKUP"
for file in "${CAMBIADOS[@]}"; do
  mkdir -p "$BACKUP/$(dirname "$file")"
  cp -p "$LIVE/$file" "$BACKUP/$file"
done
rollback() {
  estado=$?
  trap - EXIT
  if [[ "$estado" -ne 0 ]]; then
    for file in "${CAMBIADOS[@]}"; do cp -p "$BACKUP/$file" "$LIVE/$file"; done
    for file in "${NUEVOS[@]}"; do rm -f "$LIVE/$file"; done
    echo "ERROR: archivos restaurados desde $BACKUP" >&2
  fi
  exit "$estado"
}
trap rollback EXIT
for file in "${CAMBIADOS[@]}" "${NUEVOS[@]}"; do
  mkdir -p "$LIVE/$(dirname "$file")"
  cp -p "$TMP/stage/$file" "$LIVE/$file"
  chmod 0644 "$LIVE/$file"
  cmp -s "$TMP/stage/$file" "$LIVE/$file"
  if [[ "$file" == *.php ]]; then php -l "$LIVE/$file" >/dev/null; fi
done
trap - EXIT
echo "OK: llamadas internas y reproductor privado publicados."
echo "Respaldo: $BACKUP"
echo "No se ha ejecutado la reparación histórica de la prueba."
