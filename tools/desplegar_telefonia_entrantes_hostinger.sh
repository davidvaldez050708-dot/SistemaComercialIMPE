#!/usr/bin/env bash
# Corrige la IU de llamadas entrantes, sin tocar SQL, Zadarma ni su SDK remoto.
# Conserva personalizaciones locales cuando Git puede fusionarlas sin conflictos.
# Ante un conflicto, aborta ANTES de publicar cualquier archivo.
set -euo pipefail
umask 077
SRC="${IMPE_INCOMING_SOURCE_DIR:-$HOME/crm-preparacion}"
DST="${IMPE_INCOMING_PUBLIC_DIR:-$HOME/domains/fundacionrededumex.org/public_html}"
RESP="${IMPE_INCOMING_BACKUP_DIR:-$HOME/respaldos-crm}"
FILES=(
  "public/css/telefonia_host.css"
  "public/javascript/telefonia_host.js"
  "public/css/telefonia_shell.css"
  "public/javascript/telefonia_shell.js"
  "public/javascript/telefonia_persistente.js"
)
BASES=(
  "3078506f6c25b9f3c6efa828ca475c29c26605bf"
  "4cf410f52d77807e70d2e39c6e0c75eb3b4c0f15"
  "ba77890e228cb52f0af500c12014ffb6673e637a"
  "590868cb2b14e9879edddecdf42f1f53574c4931"
  "10883087c37e5e5a9990165e98017c31eae5797e"
)
[[ -d "$SRC/.git" && -f "$DST/index.php" ]] || {
  echo "ERROR: no se localizó el repositorio o la raíz pública del CRM." >&2
  exit 1
}
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

echo "1/4 Preparando cambios y comprobando personalizaciones..."
for i in "${!FILES[@]}"; do
  file="${FILES[$i]}"
  src="$SRC/$file"
  live="$DST/$file"
  out="$TMP/$file"
  [[ -f "$src" && -f "$live" ]] || {
    echo "ERROR: falta $file en Git o producción. No se publicó nada." >&2
    exit 1
  }
  mkdir -p "$(dirname "$out")"
  if cmp -s "$src" "$live"; then
    cp "$live" "$out"
    continue
  fi
  git -C "$SRC" cat-file blob "${BASES[$i]}" > "$TMP/base-$i" || {
    echo "ERROR: no existe la base histórica de $file." >&2
    exit 1
  }
  git merge-file -p "$live" "$TMP/base-$i" "$src" > "$out" || {
    echo "CONFLICTO: $file tiene modificaciones incompatibles en Hostinger." >&2
    echo "Sin archivos publicados. Revisa el conflicto antes de continuar." >&2
    exit 1
  }
done

echo "2/4 Verificando que la instalación incluya todas las correcciones..."
grep -Fq 'body:not(.telephony-host-incoming)' "$TMP/public/css/telefonia_host.css"
grep -Fq "classList.add('telephony-host-incoming')" "$TMP/public/javascript/telefonia_host.js"
grep -Fq "classList.remove('telephony-host-incoming')" "$TMP/public/javascript/telefonia_host.js"
grep -Fq 'right: -10000px; bottom: 12px' "$TMP/public/css/telefonia_shell.css"
grep -Fq 'is-raised' "$TMP/public/javascript/telefonia_shell.js"
grep -Fq 'incomingInShell' "$TMP/public/javascript/telefonia_persistente.js"
grep -Fq "state?.phase === 'incoming-finished'" "$TMP/public/javascript/telefonia_persistente.js"
if command -v node >/dev/null 2>&1; then
  for file in public/javascript/telefonia_host.js \
              public/javascript/telefonia_shell.js \
              public/javascript/telefonia_persistente.js; do
    node --check "$TMP/$file"
  done
fi

needs_update=0
for file in "${FILES[@]}"; do
  if ! cmp -s "$TMP/$file" "$DST/$file"; then
    needs_update=1
    break
  fi
done
if [[ "$needs_update" == "0" ]]; then
  echo "OK: llamadas entrantes ya tienen las correcciones. Ningún archivo modificado."
  exit 0
fi

echo "3/4 Respaldando los cinco archivos originales..."
mkdir -p "$RESP"
BACKUP="$(mktemp -d "$RESP/telefonia-entrantes-$(date +%Y%m%d-%H%M%S)-XXXXXX")"
for file in "${FILES[@]}"; do
  mkdir -p "$BACKUP/$(dirname "$file")"
  cp -p "$DST/$file" "$BACKUP/$file"
done

echo "4/4 Publicando corrección de la interfaz entrante..."
for file in "${FILES[@]}"; do
  cp "$TMP/$file" "$DST/$file"
done
echo "OK: receptor actualizado. Las llamadas salientes, Zadarma y MySQL no se modificaron."
echo "Respaldo: $BACKUP"
echo "Termina todas las llamadas, cierra sesión en el CRM y vuelve a entrar."
