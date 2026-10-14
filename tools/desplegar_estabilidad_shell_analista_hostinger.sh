#!/usr/bin/env bash
# Parche selectivo para navegación WebRTC del Analista: F5, carga sin blanco,
# autoconexión y recuperación de lease. Sin cambios en API, SDK, DB, PBX.
set -euo pipefail
umask 077

MODE="${1:---check}"
if [[ "$MODE" != "--check" && "$MODE" != "--apply" ]]; then
  echo "Uso: bash $0 --check|--apply" >&2
  exit 2
fi
SOURCE="${IMPE_SHELL_ESTABLE_SOURCE:-$HOME/crm-preparacion}"
LIVE="${IMPE_SHELL_ESTABLE_PUBLIC:-$HOME/domains/fundacionrededumex.org/public_html}"
BACKUPS="${IMPE_SHELL_ESTABLE_BACKUPS:-$HOME/respaldos-crm}"

FILES=(
  "app/views/telefonia/shell.php"
  "public/javascript/telefonia_shell.js"
  "app/controllers/TelefoniaController.php"
  "public/css/telefonia_shell.css"
)
BEFORE=(
  "310fa2f2e4917583e8177456fed5ed909913dd6f"
  "6dc7b7fad51e5685bd63e1f13ac4f9a5cee7bb8f"
  "59f35c3ae14ebd20456d6b19f88baba6712a355e"
  "639992293946becae46c390daf0cb3184d392bfc"
)
AFTER=(
  "6dc7b7fad51e5685bd63e1f13ac4f9a5cee7bb8f"
  "59f35c3ae14ebd20456d6b19f88baba6712a355e"
  "639992293946becae46c390daf0cb3184d392bfc"
  "e7dc664e3410b781965f37bc8704d7532f6170ec"
)
NEW="public/javascript/telefonia_shell_navegacion.js"

[[ -d "$SOURCE/.git" && -d "$LIVE" && ! -L "$LIVE" ]] || {
  echo "ERROR: clon Git o publicación de Hostinger no disponibles." >&2
  exit 1
}
[[ -f "$SOURCE/$NEW" && ! -L "$SOURCE/$NEW" ]] || {
  echo "ERROR: falta script nuevo. Ejecuta git pull --ff-only origin main." >&2
  exit 1
}
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
mkdir -p "$TMP/stage/$(dirname "$NEW")"
cp -p "$SOURCE/$NEW" "$TMP/stage/$NEW"
ADD_NEW=0
if [[ -e "$LIVE/$NEW" ]]; then
  [[ -f "$LIVE/$NEW" && ! -L "$LIVE/$NEW" ]] || {
    echo "CONFLICTO: script de navegación publicado no es archivo regular." >&2
    exit 1
  }
  cmp -s "$LIVE/$NEW" "$TMP/stage/$NEW" || {
    echo "CONFLICTO: script de navegación ya existe con contenido diferente." >&2
    exit 1
  }
  echo "YA ACTUALIZADO: $NEW"
else
  ADD_NEW=1
  echo "ARCHIVO NUEVO COMPATIBLE: $NEW"
fi

declare -a CHANGED=()
declare -A SHA=()
for i in "${!FILES[@]}"; do
  file="${FILES[$i]}"
  git -C "$SOURCE" cat-file -e "${BEFORE[$i]}^{commit}" &&
  git -C "$SOURCE" cat-file -e "${AFTER[$i]}^{commit}" || {
    echo "ERROR: faltan commits para $file; actualiza repositorio." >&2
    exit 1
  }
  [[ -f "$LIVE/$file" && ! -L "$LIVE/$file" ]] || {
    echo "ERROR: archivo requerido no existe en Hostinger: $file" >&2
    exit 1
  }
  SHA["$file"]="$(sha256sum "$LIVE/$file" | cut -d ' ' -f1)"
  mkdir -p "$TMP/stage/$(dirname "$file")"
  cp -p "$LIVE/$file" "$TMP/stage/$file"
  patch="$TMP/cambio_$i.patch"
  git -C "$SOURCE" diff --binary "${BEFORE[$i]}" "${AFTER[$i]}" -- "$file" > "$patch"
  [[ -s "$patch" ]] || {
    echo "ERROR: parche no encontrado para $file" >&2
    exit 1
  }
  if [[ "$file" == "app/views/telefonia/shell.php" ]] &&
       cmp -s "$TMP/stage/$file" "$SOURCE/$file"; then
    # El shell reconciliado contiene también el diario WebRTC que no existía
    # en el commit original del parche. Su igualdad exacta es idempotente.
    echo "YA ACTUALIZADO: $file (historial + navegación)"
  elif (cd "$TMP/stage" && git apply --reverse --check "$patch" >/dev/null 2>&1); then
    echo "YA ACTUALIZADO: $file"
  elif (cd "$TMP/stage" && git apply --check "$patch" >/dev/null 2>&1); then
    (cd "$TMP/stage" && git apply "$patch")
    CHANGED+=("$file")
    echo "PARCHE COMPATIBLE: $file"
  elif [[ "$file" == "app/views/telefonia/shell.php" ]] &&
       [[ "$(git -C "$SOURCE" hash-object "$LIVE/$file")" == "a039e4d61e4493a03fc739940a8b8e4f824e56e1" ]]; then
    # Única variante de Hostinger identificada por el usuario. La diferencia
    # comprobada incluye el diario pasivo WebRTC, cuyo bloque se incorporó
    # en la fuente reconciliada. NO realizar este reemplazo para hashes
    # desconocidos o personalizaciones posteriores.
    for required in 'IMPE_ANALYST_WEBRTC_PASSIVE_JOURNAL' \
                    'telefonia_analista_historial_pasivo.js' \
                    'data-incoming-answer-compact' \
                    'impePersistentPhoneStyles' \
                    'IMPE_PHONE_SHELL_CONFIG'; do
      grep -Fq "$required" "$LIVE/$file" || {
        echo "CONFLICTO: falta control histórico $required en shell publicado." >&2
        exit 1
      }
    done
    for required in 'IMPE_ANALYST_WEBRTC_PASSIVE_JOURNAL' \
                    'telefonia_analista_historial_pasivo.js' \
                    'data-incoming-register-finished' \
                    'data-incoming-retry-save' \
                    'data-shell-navigation-mask' \
                    'impeShellNavigation' \
                    'impePersistentPhoneStyles' \
                    'IMPE_PHONE_SHELL_CONFIG'; do
      grep -Fq "$required" "$SOURCE/$file" || {
        echo "ERROR: falta control $required en el shell reconciliado." >&2
        exit 1
      }
    done
    cp -p "$SOURCE/$file" "$TMP/stage/$file"
    CHANGED+=("$file")
    echo "PARCHE COMPATIBLE: shell Hostinger conocido; diario WebRTC conservado."
  elif [[ "$file" == "public/css/telefonia_shell.css" ]]; then
    # El único cambio de este commit CSS es un BLOQUE AÑADIDO al final.
    # Una hoja Hostinger puede tener personalizaciones en sus estilos de
    # teléfono; conservarlas, incorporando sólo las nuevas clases de
    # navegación. Verificar que el sufijo sea exactamente el oficial.
    cssBase="$TMP/shell_css_base.css"
    cssTarget="$TMP/shell_css_target.css"
    cssNew="$TMP/shell_css_navigation_only.css"
    git -C "$SOURCE" show "${BEFORE[$i]}:$file" > "$cssBase"
    git -C "$SOURCE" show "${AFTER[$i]}:$file" > "$cssTarget"
    cssBaseSize="$(wc -c < "$cssBase" | tr -d ' ')"
    [[ "$cssBaseSize" =~ ^[0-9]+$ && "$cssBaseSize" -gt 500 ]] || {
      echo "ERROR: base CSS inválida, producción intacta." >&2
      exit 1
    }
    head -c "$cssBaseSize" "$cssTarget" | cmp -s - "$cssBase" || {
      echo "CONFLICTO: parche CSS contiene cambios no aditivos; producción intacta." >&2
      exit 1
    }
    tail -c "+$((cssBaseSize + 1))" "$cssTarget" > "$cssNew"
    [[ -s "$cssNew" ]] || {
      echo "ERROR: bloque CSS de navegación vacío." >&2
      exit 1
    }
    cssNewSize="$(wc -c < "$cssNew" | tr -d ' ')"
    cssLiveSize="$(wc -c < "$TMP/stage/$file" | tr -d ' ')"
    if [[ "$cssLiveSize" -ge "$cssNewSize" ]] &&
       tail -c "$cssNewSize" "$TMP/stage/$file" | cmp -s - "$cssNew"; then
      cssPrior="$TMP/css_antes_de_navegacion.css"
      head -c "$((cssLiveSize - cssNewSize))" "$TMP/stage/$file" > "$cssPrior"
      if grep -Eq 'impe-shell-navigation-(mask|progress|spinner|spin)' "$cssPrior"; then
        echo "CONFLICTO: estilos de navegación duplicados antes del bloque oficial." >&2
        exit 1
      fi
      echo "YA ACTUALIZADO: $file (CSS personalizado conservado)"
      continue
    fi
    for selector in '.impe-phone-shell' '#impeCrmFrame' '.impe-phone-call' \
                    '.impe-incoming-panel' '.impe-phone-classic-link'; do
      grep -Fq "$selector" "$TMP/stage/$file" || {
        echo "CONFLICTO: CSS publicado carece de $selector; producción intacta." >&2
        exit 1
      }
    done
    for selector in '.impe-shell-navigation-mask' '.impe-shell-navigation-progress' \
                    '.impe-shell-navigation-spinner' 'impe-shell-navigation-spin'; do
      if grep -Fq "$selector" "$TMP/stage/$file"; then
        echo "CONFLICTO: existe CSS de navegación no homologado ($selector)." >&2
        exit 1
      fi
    done
    # Asegurar fin de línea entre estilos heredados y el bloque nuevo.
    printf '\n' >> "$TMP/stage/$file"
    cat "$cssNew" >> "$TMP/stage/$file"
    CHANGED+=("$file")
    echo "PARCHE COMPATIBLE: CSS personalizado conservado; añadidos sólo estilos de navegación."
  else
    echo "CONFLICTO: $file tiene cambios incompatibles. PRODUCCIÓN INTACTA." >&2
    exit 1
  fi
done

php -l "$TMP/stage/app/views/telefonia/shell.php" >/dev/null
php -l "$TMP/stage/app/controllers/TelefoniaController.php" >/dev/null
if command -v node >/dev/null 2>&1; then
  node --check "$TMP/stage/public/javascript/telefonia_shell.js"
  node --check "$TMP/stage/$NEW"
fi
grep -Fq 'data-shell-navigation-mask' "$TMP/stage/app/views/telefonia/shell.php"
grep -Fq 'impeShellNavigation' "$TMP/stage/app/views/telefonia/shell.php"
grep -Fq "!cfg.analystIncomingResults" "$TMP/stage/public/javascript/telefonia_shell.js"
grep -Fq "telefonia_shell_navegacion.js" "$TMP/stage/app/controllers/TelefoniaController.php"
grep -Fq '.impe-shell-navigation-mask' "$TMP/stage/public/css/telefonia_shell.css"
grep -Fq "last-route:" "$TMP/stage/$NEW"

if [[ "${#CHANGED[@]}" -eq 0 && "$ADD_NEW" -eq 0 ]]; then
  echo "OK: estabilidad del shell ya publicada."
  exit 0
fi
if [[ "$MODE" == "--check" ]]; then
  echo "OK: estabilidad WebRTC y navegación compatibles. PRODUCCIÓN INTACTA."
  echo "No hay escrituras a DB, archivos de grabación o configuración Zadarma."
  exit 0
fi
for file in "${CHANGED[@]}"; do
  [[ "$(sha256sum "$LIVE/$file" | cut -d ' ' -f1)" == "${SHA[$file]}" ]] || {
    echo "CONFLICTO: $file cambió tras las comprobaciones." >&2
    exit 1
  }
done
if [[ "$ADD_NEW" -eq 1 && -e "$LIVE/$NEW" ]]; then
  echo "CONFLICTO: script nuevo apareció durante comprobación." >&2
  exit 1
fi

mkdir -p "$BACKUPS"
BACKUP="$BACKUPS/shell-estable-analista-$(date +%Y%m%d-%H%M%S)-$$"
mkdir -p "$BACKUP"
for file in "${CHANGED[@]}"; do
  mkdir -p "$BACKUP/$(dirname "$file")"
  cp -p "$LIVE/$file" "$BACKUP/$file"
done

rollback() {
  status=$?
  trap - EXIT
  if [[ "$status" -ne 0 ]]; then
    for file in "${CHANGED[@]}"; do
      cp -p "$BACKUP/$file" "$LIVE/$file"
    done
    if [[ "$ADD_NEW" -eq 1 ]]; then rm -f "$LIVE/$NEW"; fi
    echo "ERROR: respaldos restaurados desde $BACKUP" >&2
  fi
  exit "$status"
}
trap rollback EXIT
if [[ "$ADD_NEW" -eq 1 ]]; then
  cp -p "$TMP/stage/$NEW" "$LIVE/$NEW"
  chmod 0644 "$LIVE/$NEW"
fi
for file in "${CHANGED[@]}"; do
  cp -p "$TMP/stage/$file" "$LIVE/$file"
  chmod 0644 "$LIVE/$file"
  cmp -s "$TMP/stage/$file" "$LIVE/$file"
done
php -l "$LIVE/app/views/telefonia/shell.php" >/dev/null
php -l "$LIVE/app/controllers/TelefoniaController.php" >/dev/null
trap - EXIT
echo "OK: shell telefónico estable publicado. Respaldo: $BACKUP"
echo "Cierra otras pestañas del CRM e inicia sesión de nuevo para probar."
echo "Sin modificaciones de MySQL, SDK Zadarma, llamadas y grabaciones."
