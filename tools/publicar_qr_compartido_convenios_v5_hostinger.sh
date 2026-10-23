#!/usr/bin/env bash
# QR compartido de Convenios V4: solo JS y CSS, sin PHP ni SQL.
set -euo pipefail
umask 077

MODE="${1:---check}"
if [[ "$MODE" != "--check" && "$MODE" != "--apply" ]]; then
  echo "Uso: bash publicar_qr_convenios_v5.sh --check|--apply" >&2
  exit 2
fi

SITE="$HOME/domains/fundacionrededumex.org/public_html"
PREP="$HOME/entregas-impe/qr-compartido-convenios-v5"
BACKUPROOT="$HOME/respaldos-impe-qr-compartido-convenios-v5"
REV="0f19ed9b9bca856fa7a8090398f7b1418c97ea51"
RAW="https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/$REV"
JS="public/javascript/convenios_borradores_v4.js"
CSS="public/css/convenios_borradores_v4.css"
JS_ANTES="8ad1fac11905799428df746975c2d359adfc659c"
CSS_ANTES="7bf5ab4a3547b08aa376c6146b8d859aecde7883"
JS_DESPUES="7354879082e6220a0ded60b1963e69fc4294f8c2"
CSS_DESPUES="c8c04459e5a202ae6917beaa48884496ff911840"

for command in curl git php mktemp cp mv; do
  command -v "$command" >/dev/null || { echo "Falta $command; SITIO INTACTO." >&2; exit 1; }
done
if [[ ! -f "$SITE/index.php" || ! -f "$SITE/$JS" || ! -f "$SITE/$CSS" ]]; then
  echo "No se encontraron los archivos del módulo V4. SITIO INTACTO." >&2
  exit 1
fi
if [[ -L "$SITE/$JS" || -L "$SITE/$CSS" ]]; then
  echo "Enlace simbólico inesperado. SITIO INTACTO." >&2
  exit 1
fi
for dep in \
  "public/javascript/convocatorias_aliado_modular.js" \
  "app/views/convocatorias/partials/aliado_programas_modular.php" \
  "public/vendor/qrcode-generator/qrcode.js" \
  "app/views/convocatorias/partials/editor_convenio_v4.php"; do
  [[ -f "$SITE/$dep" ]] || { echo "Falta dependencia $dep. SITIO INTACTO." >&2; exit 1; }
done
grep -Fq "data-impe-crear-qr" "$SITE/app/views/convocatorias/partials/aliado_programas_modular.php"
grep -Fq "data-impe-qr-canvas" "$SITE/app/views/convocatorias/partials/aliado_programas_modular.php"
grep -Fq "data-impe-qr-wrap" "$SITE/app/views/convocatorias/partials/aliado_programas_modular.php"
grep -Fq "data-borrador-v4" "$SITE/app/views/convocatorias/partials/editor_convenio_v4.php"
php -l "$SITE/app/views/convocatorias/partials/editor_convenio_v4.php" >/dev/null

mkdir -p -m 700 "$PREP"
STAGE="$(mktemp -d "$PREP/revisar-XXXXXX")"
trap 'rm -rf "$STAGE"' EXIT
echo "1. Descargando la entrega comprobada..."
for file in "$JS" "$CSS"; do
  mkdir -p "$STAGE/$(dirname "$file")"
  curl -fLsS --retry 2 "$RAW/$file" -o "$STAGE/$file"
done
if [[ "$(git hash-object "$STAGE/$JS")" != "$JS_DESPUES" ||
      "$(git hash-object "$STAGE/$CSS")" != "$CSS_DESPUES" ]]; then
  echo "Entrega descargada diferente al commit autorizado. SITIO INTACTO." >&2
  exit 1
fi
grep -Fq "IMPE_CONVENIOS_QR_COMPARTIDO_V5_20261023" "$STAGE/$JS"
grep -Fq "IMPE_CONVENIOS_QR_COMPARTIDO_V5_20261023" "$STAGE/$CSS"
if command -v node >/dev/null 2>&1; then node --check "$STAGE/$JS"; fi
echo "2. Comprobando versiones del V4 instalado..."
CURRENT_JS="$(git hash-object "$SITE/$JS")"
CURRENT_CSS="$(git hash-object "$SITE/$CSS")"

if [[ "$CURRENT_JS" == "$JS_ANTES" ]]; then
  echo "PREPARADO: $JS"
elif [[ "$CURRENT_JS" == "$JS_DESPUES" ]]; then
  echo "SIN CAMBIOS: $JS"
else
  echo "CONFLICTO: $JS tiene personalizaciones distintas. SITIO INTACTO." >&2
  exit 1
fi
if [[ "$CURRENT_CSS" == "$CSS_ANTES" ]]; then
  echo "PREPARADO: $CSS"
elif [[ "$CURRENT_CSS" == "$CSS_DESPUES" ]]; then
  echo "SIN CAMBIOS: $CSS"
else
  echo "CONFLICTO: $CSS tiene personalizaciones distintas. SITIO INTACTO." >&2
  exit 1
fi
if [[ "$MODE" == "--check" ]]; then
  echo "REVISIÓN COMPATIBLE: QR compartido de Convenios, 2 archivos, sin SQL."
  echo "SITIO INTACTO"
  exit 0
fi
if [[ "$CURRENT_JS" == "$JS_DESPUES" && "$CURRENT_CSS" == "$CSS_DESPUES" ]]; then
  echo "ENTREGA YA PUBLICADA. No hubo cambios."
  exit 0
fi
mkdir -p -m 700 "$BACKUPROOT"
BACKUP="$(mktemp -d "$BACKUPROOT/qr-convenios-v5-$(date +%Y%m%d-%H%M%S)-XXXXXX")"
for file in "$JS" "$CSS"; do
  mkdir -p "$BACKUP/$(dirname "$file")"
  cp -p "$SITE/$file" "$BACKUP/$file"
done
if [[ "$(git hash-object "$BACKUP/$JS")" != "$CURRENT_JS" ||
      "$(git hash-object "$BACKUP/$CSS")" != "$CURRENT_CSS" ]]; then
  echo "El respaldo no coincide con producción; SITIO INTACTO." >&2
  exit 1
fi
echo "3. Publicando con respaldo: $BACKUP"
[[ "$(git hash-object "$SITE/$JS")" == "$CURRENT_JS" ]] || {
  echo "Cambio concurrente en JS; SITIO INTACTO." >&2; exit 1;
}
[[ "$(git hash-object "$SITE/$CSS")" == "$CURRENT_CSS" ]] || {
  echo "Cambio concurrente en CSS; SITIO INTACTO." >&2; exit 1;
}

FINALIZADO=0
publicar_o_restaurar() {
  local status=$?
  if [[ "$FINALIZADO" == "0" ]]; then
    echo "Publicación no finalizada. Restaurando respaldo..." >&2
    cp -p "$BACKUP/$JS" "$SITE/$JS" || echo "Restaurar manualmente $JS desde $BACKUP" >&2
    cp -p "$BACKUP/$CSS" "$SITE/$CSS" || echo "Restaurar manualmente $CSS desde $BACKUP" >&2
  fi
  rm -rf "$STAGE"
  return "$status"
}
trap publicar_o_restaurar EXIT

for file in "$JS" "$CSS"; do
  if cmp -s "$STAGE/$file" "$SITE/$file"; then continue; fi
  tmp="$SITE/$file.qr-v5-$$"
  cp "$STAGE/$file" "$tmp"
  chmod --reference="$SITE/$file" "$tmp"
  mv -f "$tmp" "$SITE/$file"
  echo "PUBLICADO: $file"
done
[[ "$(git hash-object "$SITE/$JS")" == "$JS_DESPUES" ]]
[[ "$(git hash-object "$SITE/$CSS")" == "$CSS_DESPUES" ]]
FINALIZADO=1
echo "PUBLICACIÓN FINALIZADA. Respaldo: $BACKUP"
echo "Borradores, programas, enlaces, QR único, filtros mensuales y BD conservados."
