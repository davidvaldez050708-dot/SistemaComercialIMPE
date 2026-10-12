#!/usr/bin/env bash
# Ajuste visual del marcador (Manrope, Bootstrap Icons, proporciones).
# Sólo cuatro archivos de presentación. No modifica PBX, BD, credenciales,
# permisos ni el estado de una llamada. Requiere terminar llamadas activas.
set -euo pipefail
umask 077
SRC="${IMPE_DIALER_STYLE_SOURCE_DIR:-$HOME/crm-preparacion}"
DST="${IMPE_DIALER_STYLE_PUBLIC_DIR:-$HOME/domains/fundacionrededumex.org/public_html}"
RESP="${IMPE_DIALER_STYLE_BACKUP_DIR:-$HOME/respaldos-crm}"
FILES=(
  "app/views/telefonia/shell.php"
  "public/javascript/telefonia_teclado.js"
  "public/css/telefonia_teclado.css"
  "public/css/telefonia_shell.css"
)
BASES=(
  "e1766f7de4f22ba7433092294fd337256988f1d9"
  "b3008fd7efb9096c6f8f8eea52db771a91c3fb4e"
  "8fc1f37caf003feafbc929c98768f108ed2a11b9"
  "1be5ce91f7ce0d30104faba62ca3529abef3598f"
)
[[ -d "$SRC/.git" && -f "$DST/index.php" ]] || {
  echo "ERROR: no se encontró el repositorio o la instalación de Hostinger." >&2
  exit 1
}
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
echo "1/4 Preparando cambios y preservando personalizaciones..."
for i in "${!FILES[@]}"; do
  file="${FILES[$i]}"
  srcfile="$SRC/$file"
  live="$DST/$file"
  target="$TMP/$file"
  [[ -f "$srcfile" && -f "$live" ]] || {
    echo "ERROR: falta $file en Git o en producción. Nada publicado." >&2
    exit 1
  }
  mkdir -p "$TMP/$(dirname "$file")"
  git -C "$SRC" cat-file blob "${BASES[$i]}" > "$TMP/base-$i" || {
    echo "ERROR: falta la versión base de $file en Git. Nada publicado." >&2
    exit 1
  }
  if cmp -s "$live" "$srcfile"; then
    cp "$live" "$target"
    continue
  fi

  # La primera versión suele tener cambios locales añadidos al FINAL del CSS.
  # Conservarlos exactamente. Si la edición ocurrió en otra zona, intentar
  # un merge Git normal y abortar antes de publicar si hay conflicto.
  if php -r '
    $base=file_get_contents($argv[1]);
    $live=file_get_contents($argv[2]);
    $new=file_get_contents($argv[3]);
    if (str_starts_with($live,$new)) { $merged=$live; }
    elseif (str_starts_with($live,$base)) {
        $merged=$new . substr($live,strlen($base));
    } else { exit(3); }
    exit(file_put_contents($argv[4],$merged)===false ? 2 : 0);
  ' "$TMP/base-$i" "$live" "$srcfile" "$target"; then
    :
  else
    git merge-file -p "$live" "$TMP/base-$i" "$srcfile" > "$target" || {
      echo "CONFLICTO: $file tiene cambios incompatibles. No se publicó nada." >&2
      exit 1
    }
  fi
done

echo "2/4 Validando sintaxis y resultado integrado..."
php -l "$TMP/app/views/telefonia/shell.php"
if command -v node >/dev/null 2>&1; then
  node --check "$TMP/public/javascript/telefonia_teclado.js"
fi
grep -Fq "fonts.googleapis.com/css2?family=Manrope" "$TMP/app/views/telefonia/shell.php"
grep -Fq "bootstrap-icons@1.13.1" "$TMP/app/views/telefonia/shell.php"
grep -Fq "bi bi-backspace" "$TMP/public/javascript/telefonia_teclado.js"
grep -Fq "bi bi-telephone-fill" "$TMP/public/javascript/telefonia_teclado.js"
grep -Fq ".impe-dialer button,.impe-dialer input" "$TMP/public/css/telefonia_teclado.css"
grep -Fq "'Manrope', sans-serif" "$TMP/public/css/telefonia_shell.css"

if cmp -s "$TMP/app/views/telefonia/shell.php" "$DST/app/views/telefonia/shell.php" &&
   cmp -s "$TMP/public/javascript/telefonia_teclado.js" "$DST/public/javascript/telefonia_teclado.js" &&
   cmp -s "$TMP/public/css/telefonia_teclado.css" "$DST/public/css/telefonia_teclado.css" &&
   cmp -s "$TMP/public/css/telefonia_shell.css" "$DST/public/css/telefonia_shell.css"; then
   echo "OK: el diseño telefónico ya está actualizado; ningún archivo modificado."
   exit 0
fi

echo "3/4 Respaldando los archivos anteriores..."
mkdir -p "$RESP"
BACKUP="$(mktemp -d "$RESP/telefonia-estilo-$(date +%Y%m%d-%H%M%S)-XXXXXX")"
for file in "${FILES[@]}"; do
  mkdir -p "$BACKUP/$(dirname "$file")"
  cp -p "$DST/$file" "$BACKUP/$file"
done
echo "4/4 Publicando los ajustes visuales..."
for file in "${FILES[@]}"; do
  cp "$TMP/$file" "$DST/$file"
done
php -l "$DST/app/views/telefonia/shell.php"
echo "OK: teléfono alineado al diseño del CRM. Zadarma y MySQL intactos."
echo "Respaldo: $BACKUP"
echo "Termina la sesión telefónica de prueba, vuelve a entrar y recarga con Ctrl+F5."
