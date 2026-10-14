#!/usr/bin/env bash
# Parche SELECTIVO: identificadores de grabación decimal Zadarma.
# No modifica BD, sesiones, webhooks, SDK, vistas ni credenciales.
set -euo pipefail
umask 077
MODE="${1:---check}"
[[ "$MODE" == "--check" || "$MODE" == "--apply" ]] || {
  echo "Uso: bash $0 --check|--apply" >&2
  exit 2
}
SOURCE="${IMPE_DECIMAL_SOURCE:-$HOME/crm-preparacion}"
LIVE="${IMPE_DECIMAL_PUBLIC:-$HOME/domains/fundacionrededumex.org/public_html}"
BACKUPS="${IMPE_DECIMAL_BACKUPS:-$HOME/respaldos-crm}"
NEW="app/helpers/ZadarmaPbxIdentifierHelper.php"
FILES=(
  "app/services/TelefoniaInternaGrabacionService.php"
  "app/services/ZadarmaRecordingService.php"
)
BASE="10f689e1a086ba24d40d623b945db47606f8933a"
TARGET="0fe78050363d9f27712816695975f1aa08538fde"

[[ -d "$SOURCE/.git" && -d "$LIVE" && ! -L "$LIVE" ]] || {
  echo "ERROR: clon o publicación de Hostinger ausentes." >&2; exit 1;
}
for rev in "$BASE" "$TARGET"; do
  git -C "$SOURCE" cat-file -e "${rev}^{commit}" || {
    echo "ERROR: actualiza Git con git pull --ff-only origin main." >&2
    exit 1
  }
done
[[ -f "$SOURCE/$NEW" && ! -L "$SOURCE/$NEW" ]] || {
  echo "ERROR: falta validador de identificadores Zadarma." >&2; exit 1;
}

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
mkdir -p "$TMP/stage/$(dirname "$NEW")"
cp -p "$SOURCE/$NEW" "$TMP/stage/$NEW"

NUEVO=0
if [[ -e "$LIVE/$NEW" ]]; then
  [[ -f "$LIVE/$NEW" && ! -L "$LIVE/$NEW" ]] || {
    echo "CONFLICTO: validador publicado no es archivo seguro." >&2; exit 1;
  }
  cmp -s "$LIVE/$NEW" "$TMP/stage/$NEW" || {
    echo "CONFLICTO: validador existente es diferente; producción intacta." >&2; exit 1;
  }
else
  NUEVO=1
  echo "ARCHIVO NUEVO COMPATIBLE: $NEW"
fi

declare -a CAMBIADOS=()
declare -A HASH=()
for file in "${FILES[@]}"; do
  [[ -f "$LIVE/$file" && ! -L "$LIVE/$file" ]] || {
    echo "ERROR: no existe archivo publicado: $file" >&2; exit 1;
  }
  mkdir -p "$TMP/stage/$(dirname "$file")"
  cp -p "$LIVE/$file" "$TMP/stage/$file"
  HASH["$file"]="$(sha256sum "$LIVE/$file" | cut -d' ' -f1)"
  patch="$TMP/$(basename "$file").patch"
  git -C "$SOURCE" diff --binary "$BASE" "$TARGET" -- "$file" > "$patch"
  [[ -s "$patch" ]] || { echo "ERROR: parche vacío: $file" >&2; exit 1; }
  if (cd "$TMP/stage" && git apply --reverse --check "$patch" >/dev/null 2>&1); then
    # Un parche ya presente no autoriza sobrescribir ni aceptar cambios
    # locales adicionales que la herramienta no conoce.
    if ! git -C "$SOURCE" show "$TARGET:$file" | cmp -s - "$TMP/stage/$file"; then
      echo "CONFLICTO: $file contiene cambios ajenos al parche. Sitio intacto." >&2
      exit 1
    fi
    echo "YA ACTUALIZADO: $file"
  elif (cd "$TMP/stage" && git apply --check "$patch" >/dev/null 2>&1); then
    (cd "$TMP/stage" && git apply "$patch")
    CAMBIADOS+=("$file")
    echo "PARCHE COMPATIBLE: $file"
  else
    echo "CONFLICTO: $file no coincide con el código publicado. Sitio intacto." >&2
    exit 1
  fi
done

php -l "$TMP/stage/$NEW" >/dev/null
for file in "${FILES[@]}"; do php -l "$TMP/stage/$file" >/dev/null; done
grep -Fq 'decodificarEstadisticas' "$TMP/stage/${FILES[0]}"
grep -Fq 'callIdValido($callId)' "$TMP/stage/${FILES[1]}"
grep -Fq 'json_decode($normalizado' "$TMP/stage/$NEW"

if [[ "${#CAMBIADOS[@]}" -eq 0 && "$NUEVO" -eq 0 ]]; then
  echo "OK: corrección decimal Zadarma ya publicada."
  exit 0
fi
if [[ "$MODE" == "--check" ]]; then
  echo "OK: parche compatible. PRODUCCIÓN INTACTA."
  echo "Sólo validador y servicios de grabación internos."
  exit 0
fi
for file in "${CAMBIADOS[@]}"; do
  [[ "$(sha256sum "$LIVE/$file" | cut -d' ' -f1)" == "${HASH[$file]}" ]] || {
    echo "CONFLICTO: $file fue alterado desde la comprobación." >&2; exit 1;
  }
done
if [[ "$NUEVO" -eq 1 && -e "$LIVE/$NEW" ]]; then
  echo "CONFLICTO: validador apareció durante la comprobación." >&2; exit 1
fi

mkdir -p "$BACKUPS"
BACKUP="$BACKUPS/decimal-zadarma-$(date +%Y%m%d-%H%M%S)-$$"
mkdir -p "$BACKUP"
for file in "${CAMBIADOS[@]}"; do
  mkdir -p "$BACKUP/$(dirname "$file")"
  cp -p "$LIVE/$file" "$BACKUP/$file"
done
rollback() {
  code=$?
  trap - EXIT
  if [[ "$code" -ne 0 ]]; then
    for file in "${CAMBIADOS[@]}"; do
      cp -p "$BACKUP/$file" "$LIVE/$file"
    done
    if [[ "$NUEVO" -eq 1 ]]; then rm -f "$LIVE/$NEW"; fi
    echo "ERROR: publicación restaurada. Respaldo $BACKUP" >&2
  fi
  exit "$code"
}
trap rollback EXIT
if [[ "$NUEVO" -eq 1 ]]; then
  mkdir -p "$LIVE/$(dirname "$NEW")"
  cp -p "$TMP/stage/$NEW" "$LIVE/$NEW"
  chmod 0644 "$LIVE/$NEW"
fi
for file in "${CAMBIADOS[@]}"; do
  cp -p "$TMP/stage/$file" "$LIVE/$file"
  chmod 0644 "$LIVE/$file"
  cmp -s "$TMP/stage/$file" "$LIVE/$file"
done
php -l "$LIVE/$NEW" >/dev/null
for file in "${FILES[@]}"; do php -l "$LIVE/$file" >/dev/null; done
trap - EXIT
echo "OK: identificadores decimales Zadarma habilitados. Respaldo: $BACKUP"
echo "SIN CAMBIOS en datos, webhooks, SDK, vistas y credenciales."
