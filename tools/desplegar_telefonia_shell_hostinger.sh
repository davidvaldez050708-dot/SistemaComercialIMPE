#!/usr/bin/env bash
# Publica la navegación de telefonía integrada y mantiene el modo anterior
# disponible si fuera necesario. Nunca modifica PBX, BD ni secretos.
# Ante un conflicto Git aborta ANTES de publicar cualquier archivo.
set -euo pipefail
umask 077
SRC="${IMPE_SHELL_SOURCE_DIR:-$HOME/crm-preparacion}"
DST="${IMPE_SHELL_PUBLIC_DIR:-$HOME/domains/fundacionrededumex.org/public_html}"
RESP="${IMPE_SHELL_BACKUP_DIR:-$HOME/respaldos-crm}"

EXISTING=(
  "app/controllers/LoginController.php"
  "app/controllers/TelefoniaController.php"
  "public/javascript/telefonia_persistente.js"
  "public/javascript/telefonia_host.js"
  "app/views/telefonia/host.php"
  "public/css/telefonia_host.css"
  "app/views/telefonia/recepcion_marketing.php"
  "public/css/telefonia_recepcion_marketing.css"
)
BASES=(
  "c55fa9432161bf3830e2880b310071fe7a959d8d"
  "ae9cb736fcd968f1f70ba05597a2bc5f5686ff54"
  "6e662efdc9b4e88173f5f300a7237dca221d972b"
  "466023e271162f98452e29ab73d6a3d46fdb2454"
  "c886328f27abeebe289999e1412771932c0a7447"
  "a53b9ce0a6c6096788cd921e4999f4a981dbd6da"
  "bad0ff9e25ac5cc3d06e9021f6d7393e4700d792"
  "080cab618c94679cbe7da4bc74a169234f364ce0"
)
MARKERS=(
  "\$destinoInicio = 'index.php?controller=home&action=index';"
  "public function shell()"
  "return shell.ensureHost();"
  "const modoEmbebido = visualizacionHost === 'embed';"
  "\$motorTelefonicoEmbebido ="
  "/* WebRTC embebido:"
  "data-marketing-reception-auto"
  "/* Configuración opcional de conexión automática"
)
NEW=(
  "app/views/telefonia/shell.php"
  "public/javascript/telefonia_shell.js"
  "public/css/telefonia_shell.css"
)
# Versión inicial de los archivos nuevos: permite actualizar sin pisar
# personalizaciones de una instalación Hostinger anterior.
NEW_BASES=(
  "837dd40a0ff0f6130efa7774fd7802d27a31a34d"
  "b014e07210a3fd09c2df832ab64bac95520b319e"
  "1be5ce91f7ce0d30104faba62ca3529abef3598f"
)
[[ -d "$SRC/.git" && -f "$DST/index.php" ]] || {
  echo "ERROR: no se encuentra el repositorio o la raíz de producción." >&2
  exit 1
}
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

echo "1/4 Fusionando cambios y validando versiones..."
for i in "${!EXISTING[@]}"; do
  file="${EXISTING[$i]}"
  src="$SRC/$file"
  live="$DST/$file"
  out="$TMP/$file"
  [[ -f "$src" && -f "$live" ]] || { echo "ERROR: falta $file" >&2; exit 1; }
  mkdir -p "$TMP/$(dirname "$file")"
  grep -Fq "${MARKERS[$i]}" "$src" || {
    echo "ERROR: $file no tiene la versión prevista en Git." >&2; exit 1;
  }

  if [[ "$file" == "public/css/telefonia_host.css" ||
        "$file" == "public/css/telefonia_recepcion_marketing.css" ]]; then
    php -r '
      $git=file_get_contents($argv[1]);
      $prod=file_get_contents($argv[2]);
      $mark=$argv[4];
      $at=strpos($git,$mark);
      if ($at===false) exit(2);
      $block=substr($git,$at);
      if (strpos($prod,$mark)!==false) {
        if (strpos($prod,$block)===false) {
          fwrite(STDERR,"Versión local del CSS diferente; abortando.\n");exit(2);
        }
        $merged=$prod;
      } else {
        $merged=rtrim($prod)."\n\n".$block;
      }
      if (file_put_contents($argv[3],$merged)===false) exit(2);
    ' "$src" "$live" "$out" "${MARKERS[$i]}" || {
      echo "ERROR: conflicto CSS en $file. Nada publicado." >&2; exit 1;
    }
    continue
  fi

  if cmp -s "$src" "$live"; then
    cp "$live" "$out"
    continue
  fi
  git -C "$SRC" cat-file blob "${BASES[$i]}" > "$TMP/base-$i" || {
    echo "ERROR: falta la versión base de $file." >&2; exit 1;
  }
  git merge-file -p "$live" "$TMP/base-$i" "$src" > "$out" || {
    echo "CONFLICTO: $file tiene cambios incompatibles con Hostinger." >&2
    echo "No se publicó nada. Conservar los archivos actuales." >&2
    exit 1
  }
done

for i in "${!NEW[@]}"; do
  file="${NEW[$i]}"
  [[ -f "$SRC/$file" ]] || { echo "ERROR: archivo nuevo faltante $file" >&2; exit 1; }
  mkdir -p "$TMP/$(dirname "$file")"
  if [[ ! -f "$DST/$file" ]] || cmp -s "$DST/$file" "$SRC/$file"; then
    cp "$SRC/$file" "$TMP/$file"
    continue
  fi

  # Una versión anterior del mismo shell ya puede existir en producción.
  # Fusionar con su base conocida, sin sobreescribir cambios de Hostinger.
  git -C "$SRC" cat-file blob "${NEW_BASES[$i]}" > "$TMP/new-base-$i" || {
    echo "ERROR: falta la base Git para $file." >&2
    exit 1
  }
  git merge-file -p "$DST/$file" "$TMP/new-base-$i" "$SRC/$file" > "$TMP/$file" || {
    echo "CONFLICTO: $file difiere de la versión base; nada publicado." >&2
    exit 1
  }
done

echo "2/4 Comprobando sintaxis y dependencias..."
for file in app/controllers/LoginController.php app/controllers/TelefoniaController.php \
            app/views/telefonia/host.php app/views/telefonia/recepcion_marketing.php \
            app/views/telefonia/shell.php; do
  php -l "$TMP/$file"
done
if command -v node >/dev/null 2>&1; then
  node --check "$TMP/public/javascript/telefonia_persistente.js"
  node --check "$TMP/public/javascript/telefonia_host.js"
  node --check "$TMP/public/javascript/telefonia_shell.js"
fi
grep -Fq 'public function shell()' "$TMP/app/controllers/TelefoniaController.php"
grep -Fq 'return shell.ensureHost();' "$TMP/public/javascript/telefonia_persistente.js"
grep -Fq 'data-shell-host-region' "$TMP/app/views/telefonia/shell.php"
grep -Fq 'data-marketing-reception-auto' "$TMP/app/views/telefonia/recepcion_marketing.php"

echo "3/4 Respaldando los archivos afectados..."
mkdir -p "$RESP"
BACKUP="$(mktemp -d "$RESP/telefonia-shell-$(date +%Y%m%d-%H%M%S)-XXXXXX")"
for file in "${EXISTING[@]}" "${NEW[@]}"; do
  if [[ -f "$DST/$file" ]]; then
    mkdir -p "$BACKUP/$(dirname "$file")"
    cp -p "$DST/$file" "$BACKUP/$file"
  fi
done
echo "4/4 Publicando telefonía integrada..."
for file in "${EXISTING[@]}" "${NEW[@]}"; do
  mkdir -p "$DST/$(dirname "$file")"
  cp "$TMP/$file" "$DST/$file"
done
php -l "$DST/app/controllers/LoginController.php"
php -l "$DST/app/controllers/TelefoniaController.php"
php -l "$DST/app/views/telefonia/shell.php"
echo "OK: shell WebRTC integrado publicado; sin cambios en MySQL o Zadarma."
echo "Respaldo: $BACKUP"
echo "La próxima sesión con extensión activa iniciará navegación persistente."
echo "Si falla WebRTC, prueba Inicio clásico en /index.php?controller=home&action=index."
