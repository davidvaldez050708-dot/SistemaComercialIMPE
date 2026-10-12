#!/usr/bin/env bash
# Publicación conservadora del modal CRM para entrantes.
# --check prepara y valida sin escribir en producción; --apply respalda y publica.
set -euo pipefail
umask 077
MODE="--check"
if [[ "$#" -gt 0 ]]; then MODE="$1"; fi
[[ "$MODE" == "--check" || "$MODE" == "--apply" ]] || {
  echo "Uso: bash tools/desplegar_modal_entrantes_hostinger.sh --check|--apply" >&2
  exit 2
}
SRC="$HOME/crm-preparacion"
DST="$HOME/domains/fundacionrededumex.org/public_html"
BACKUPS="$HOME/respaldos-crm"
BASE_REV="00e79124af6b77459e79fd8f47437ac2c2eaf1dc"
FILES="public/javascript/telefonia_entrantes_bridge.js public/javascript/telefonia_host.js public/javascript/telefonia_shell.js public/css/telefonia_shell.css app/views/telefonia/host.php app/views/telefonia/shell.php"
[[ -d "$SRC/.git" && -f "$DST/index.php" ]] || {
  echo "ERROR: no se encontró el repositorio o el sitio público." >&2; exit 1
}
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
echo "1/4 Preparando seis archivos sin modificar Hostinger..."
for p in $FILES; do
  [[ -f "$SRC/$p" ]] || { echo "ERROR: falta $p en Git." >&2; exit 1; }
  mkdir -p "$TMP/$(dirname "$p")"
  if [[ "$p" == "public/javascript/telefonia_entrantes_bridge.js" ]]; then
    if [[ ! -f "$DST/$p" ]]; then
      cp "$SRC/$p" "$TMP/$p"
      continue
    fi
    # En producción ya existe una versión anterior del puente.
    # Fusionar sus posibles personalizaciones, no rechazar por cambiar Git.
    if cmp -s "$DST/$p" "$SRC/$p"; then
      cp "$DST/$p" "$TMP/$p"
      continue
    fi
    # El puente fue publicado en varias revisiones anteriores.
    # Su antigua base 39f306f producía un falso conflicto si Hostinger
    # ya tenía 81c9a2d (la última versión visual antes del SDK directo).
    # Hash Git del contenido garantiza que NO había ediciones locales:
    # únicamente en ese caso se reemplaza por la versión actual.
    BRIDGE_HASH="$(git -C "$SRC" hash-object "$DST/$p")"
    case "$BRIDGE_HASH" in
      47f6c0a9e2edfcc5feeb7357f85986d1ae70c181|\
      b7e59bdad920d42d9d0479d2e7ed1f284455d81a|\
      ef41bba628e89e882b14d624d961430b1f661ad7|\
      ec8d56171df6d2cfe9bc5785de556d32345102e0|\
      f15a7772c1251b6e618743706d72bc4f9203f41a)
        echo "Puente anterior reconocido y sin modificaciones locales; actualización segura."
        cp "$SRC/$p" "$TMP/$p"
        continue
        ;;
    esac
    # Si la copia sí fue personalizada, usar la revisión más reciente
    # como base de una fusión de tres vías. Nada se publica ante conflicto.
    git -C "$SRC" show "81c9a2dd04b10e71678311e92249a327ed62fd2f:$p" > "$TMP/base" || {
      echo "ERROR: falta la versión previa del puente en Git." >&2; exit 1
    }
    if ! git merge-file -p "$DST/$p" "$TMP/base" "$SRC/$p" > "$TMP/$p"; then
      echo "CONFLICTO: puente Zadarma con ediciones locales desconocidas; no se publicó nada." >&2
      echo "Hash del archivo público: $BRIDGE_HASH" >&2
      echo "Es necesario comparar esas diferencias antes de sustituirlo." >&2
      exit 1
    fi
    echo "Puente personalizado fusionado sin conflicto; verificando resultado."
    continue
  fi
  [[ -f "$DST/$p" ]] || { echo "ERROR: falta el archivo público $p." >&2; exit 1; }
  if cmp -s "$SRC/$p" "$DST/$p"; then
    cp "$DST/$p" "$TMP/$p"
    continue
  fi
  if [[ "$p" == "public/javascript/telefonia_shell.js" ]]; then
    # La versión de Hostinger que ya permite contestar era más reciente
    # que ambas bases de fusión históricas usadas por este script.
    # Un archivo idéntico a cualquiera de las versiones que publicamos
    # está probado por su SHA Git: no contiene cambios locales.
    SHELL_HASH="$(git -C "$SRC" hash-object "$DST/$p")"
    # Caso REAL diagnosticado en Hostinger (SHA proporcionado por git):
    # copia de 8047ff5 sin el ajuste de elevar el aviso de conexión.
    # Validar su origen reconstruyendo byte por byte esa única diferencia.
    if [[ "$SHELL_HASH" == "45e80cccb3e104917aee74122b21ebf8a68498cf" ]]; then
      git -C "$SRC" show "8047ff5d6fab58d77732daaa27ff5fa05f63511b:$p" \
        > "$TMP/base-hostinger" || {
          echo "ERROR: falta la revisión 8047ff5 para verificar el shell." >&2; exit 1
        }
      # El archivo público retiró tres líneas: el aviso de superposición
      # y sus dos comentarios. Verificar las tres, no sólo la instrucción.
      for LOCAL_RULE in \
        'El aviso de conexión y "Mostrar llamada" son ambos flotantes:' \
        'nunca apilarlos uno encima del otro.' \
        "status.classList.toggle('is-raised', !restore.hidden);"; do
        if ! grep -Fq "$LOCAL_RULE" "$TMP/base-hostinger" ||
           ! grep -Fq "$LOCAL_RULE" "$SRC/$p"; then
          echo "CONFLICTO: falta un fragmento del ajuste esperado." >&2
          exit 1
        fi
      done
      sed \
        -e '/El aviso de conexión y "Mostrar llamada" son ambos flotantes:/d' \
        -e '/nunca apilarlos uno encima del otro./d' \
        -e "/status\\.classList\\.toggle('is-raised', !restore\\.hidden);/d" \
        "$TMP/base-hostinger" > "$TMP/shell-historico-normalizado"
      if [[ "$(git -C "$SRC" hash-object "$TMP/shell-historico-normalizado")" != "$SHELL_HASH" ]]; then
        echo "CONFLICTO: el shell publicado difiere del ajuste histórico comprobado." >&2
        exit 1
      fi
      # Restaurar cronómetro y acciones instantáneas de GitHub conservando
      # la eliminación del aviso solapado que ya tenía Hostinger.
      sed \
        -e '/El aviso de conexión y "Mostrar llamada" son ambos flotantes:/d' \
        -e '/nunca apilarlos uno encima del otro./d' \
        -e "/status\\.classList\\.toggle('is-raised', !restore\\.hidden);/d" \
        "$SRC/$p" > "$TMP/$p"
      echo "Shell Hostinger reconocido exactamente; se conserva su ajuste del aviso."
      continue
    fi
    # En revisiones futuras, cualquier versión exacta del historial Git
    # puede actualizarse sin tener que ampliar una lista manual de SHA.
    SHELL_HISTORICO=0
    while IFS= read -r revision; do
      if [[ "$(git -C "$SRC" rev-parse "$revision:$p")" == "$SHELL_HASH" ]]; then
        SHELL_HISTORICO=1
        break
      fi
    done < <(git -C "$SRC" log --format='%H' -- "$p")
    if [[ "$SHELL_HISTORICO" == 1 ]]; then
      echo "Shell de una revisión Git conocida; actualización segura."
      cp "$SRC/$p" "$TMP/$p"
      continue
    fi
    # Si Hostinger personalizó el shell, fusionar con la última versión
    # *realmente publicada* como base común, no con el primer modal.
    git -C "$SRC" show "cf1c1644baa2ed4275759555db3814b96fe06f93:$p" \
      > "$TMP/base-shell" || {
        echo "ERROR: falta el shell base actualizado en Git." >&2; exit 1
      }
    if git merge-file -p "$DST/$p" "$TMP/base-shell" "$SRC/$p" > "$TMP/$p"; then
      # Una fusión sintácticamente limpia puede conservar referencias
      # obsoletas (p. ej. renombrar callHelp sin actualizar su uso).
      # Verificar los vínculos imprescindibles antes de autorizarla.
      if ! grep -Fq "const callHelp = document.querySelector('[data-shell-call-help]');" "$TMP/$p" ||
         ! grep -Fq 'providerBridge()?.answer()' "$TMP/$p" ||
         ! grep -Fq 'const customVisible = incoming' "$TMP/$p" ||
         ! grep -Fq 'pendingIncomingAction' "$TMP/$p"; then
        echo "CONFLICTO: el shell local fusiona pero no cumple controles/estados WebRTC." >&2
        echo "Validaciones faltantes en el shell temporal:" >&2
        for marker in \
          "const callHelp = document.querySelector('[data-shell-call-help]');" \
          'providerBridge()?.answer()' \
          'const customVisible = incoming' \
          'pendingIncomingAction'; do
          if ! grep -Fq "$marker" "$TMP/$p"; then
            echo "  - FALTA: $marker" >&2
          fi
        done
        echo "No se publicó nada. Ejecuta: bash tools/diagnosticar_shell_telefonia_hostinger.sh --check" >&2
        exit 1
      fi
      echo "Shell personalizado fusionado y controles verificados."
      continue
    fi
    echo "CONFLICTO: shell telefónico con cambios locales incompatibles." >&2
    echo "SHA local $SHELL_HASH. No se publicó nada." >&2
    exit 1
  fi
  git -C "$SRC" show "$BASE_REV:$p" > "$TMP/base" || {
    echo "ERROR: falta la base histórica de Git para $p." >&2; exit 1
  }
  if ! git merge-file -p "$DST/$p" "$TMP/base" "$SRC/$p" > "$TMP/$p"; then
    # La producción también puede partir del modal anterior ya publicado.
    # Reintentar la fusión con esa versión exacta, nunca sobrescribir
    # ciegamente un archivo con cambios locales de Hostinger.
    git -C "$SRC" show "39f306fd998de860a515df9222700669b2d96dab:$p" > "$TMP/base-reciente" || {
      echo "ERROR: falta la base Git del modal anterior." >&2; exit 1
    }
    if ! git merge-file -p "$DST/$p" "$TMP/base-reciente" "$SRC/$p" > "$TMP/$p"; then
      echo "CONFLICTO: $p tiene personalizaciones incompatibles. No se publicó nada." >&2
      exit 1
    fi
  fi
done
echo "2/4 Validando PHP, JavaScript y enlace real a Zadarma..."
grep -Fq 'data-incoming-answer' "$TMP/app/views/telefonia/shell.php"
grep -Fq 'persistent-phone-panel impe-incoming-panel' "$TMP/app/views/telefonia/shell.php"
grep -Fq 'impePersistentPhoneStyles' "$TMP/app/views/telefonia/shell.php"
grep -Fq 'data-incoming-drag-handle' "$TMP/app/views/telefonia/shell.php"
grep -Fq 'data-incoming-answer-compact' "$TMP/app/views/telefonia/shell.php"
if grep -Fq 'data-incoming-provider' "$TMP/app/views/telefonia/shell.php"; then
  echo "ERROR: regresó el enlace innecesario a Zadarma" >&2; exit 1
fi
grep -Fq 'telefonia_entrantes_bridge.js' "$TMP/app/views/telefonia/host.php"
grep -Fq 'IMPE_ZADARMA_INCOMING' "$TMP/public/javascript/telefonia_host.js"
grep -Fq 'sdk[method].apply(sdk, args || [])' "$TMP/public/javascript/telefonia_entrantes_bridge.js" || {
  echo "ERROR: el puente preparado no utiliza el SDK de Zadarma." >&2
  exit 1
}
grep -Fq "answer: () => perform('contestar', 'answer'" "$TMP/public/javascript/telefonia_entrantes_bridge.js"
grep -Fq "hangup: () => perform('colgar', 'finishCall'" "$TMP/public/javascript/telefonia_entrantes_bridge.js"
grep -Fq "providerBridge()?.answer()" "$TMP/public/javascript/telefonia_shell.js"
grep -Fq 'const customVisible = incoming' "$TMP/public/javascript/telefonia_shell.js"
php -l "$TMP/app/views/telefonia/shell.php"
php -l "$TMP/app/views/telefonia/host.php"
if command -v node >/dev/null 2>&1; then
  for p in public/javascript/telefonia_entrantes_bridge.js public/javascript/telefonia_host.js public/javascript/telefonia_shell.js; do
    node --check "$TMP/$p"
  done
fi
CHANGED=""
for p in $FILES; do
  if [[ ! -f "$DST/$p" ]] || ! cmp -s "$TMP/$p" "$DST/$p"; then
    CHANGED="$CHANGED $p"
  fi
done
if [[ -z "$CHANGED" ]]; then
  # El contenido puede estar actualizado pero modo 600 por umask 077.
  BRIDGE="$DST/public/javascript/telefonia_entrantes_bridge.js"
  if [[ -f "$BRIDGE" && "$(stat -c '%a' "$BRIDGE")" != 644 ]]; then
    if [[ "$MODE" == "--apply" ]]; then
      chmod 0644 "$BRIDGE"
      echo "OK: puente ya actualizado y permisos HTTP corregidos (644)."
    else
      echo "AVISO: puente WebRTC con permisos que pueden causar HTTP 403."
      echo "Aplica --apply o utiliza tools/reparar_assets_telefonia_hostinger.sh."
    fi
  else
    echo "OK: recepción CRM ya actualizada. Sin cambios."
  fi
  exit 0
fi
echo "Archivos para publicar:$CHANGED"
if [[ "$MODE" == "--check" ]]; then
  echo "OK: comprobación previa sin modificar el CRM público."
  exit 0
fi
echo "3/4 Respaldando archivos originales y registrando nuevos..."
mkdir -p "$BACKUPS"
BACKUP="$(mktemp -d "$BACKUPS/recepcion-crm-$(date +%Y%m%d-%H%M%S)-XXXXXX")"
for p in $CHANGED; do
  if [[ -f "$DST/$p" ]]; then
    mkdir -p "$BACKUP/$(dirname "$p")"
    cp -p "$DST/$p" "$BACKUP/$p"
  else
    echo "$p" >> "$BACKUP/archivos-nuevos.txt"
  fi
done
echo "4/4 Publicando únicamente la interfaz de recepción..."
for p in $CHANGED; do
  mkdir -p "$DST/$(dirname "$p")"
  cp "$TMP/$p" "$DST/$p"
done
for p in $FILES; do
  cmp -s "$TMP/$p" "$DST/$p" || {
    echo "ERROR: no coincide $p. Respaldo: $BACKUP" >&2
    exit 1
  }
done
# Publicable vía HTTP: umask 077 crea nuevos archivos en modo 600.
chmod 0644 "$DST/public/javascript/telefonia_entrantes_bridge.js"
echo "OK: modal entrante publicado; Zadarma y llamadas salientes no modificados."
echo "Respaldo: $BACKUP"
echo "Cierra todas las pestañas del CRM y vuelve a iniciar Marketing."
