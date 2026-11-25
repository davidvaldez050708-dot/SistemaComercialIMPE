#!/usr/bin/env bash
set -eu
umask 077

if [ "$#" -ne 4 ]; then
  printf '%s\n' 'Uso: bash check.sh COMMIT SITIO PRIVADO PAQUETE' >&2
  exit 1
fi
REVISION="$1"
SITIO="$2"
PRIVADO="$3"
PAQUETE="$4"
printf '%s\n' "$REVISION" | grep -Eq '^[0-9a-f]{40}$'
test -d "$SITIO"
test -d "$PRIVADO"
test -d "$PAQUETE"
BASE="https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/$REVISION"

for ARCHIVO in deploy.php manifest.json
do
  curl --fail --silent --show-error --retry 2 \
    "$BASE/tools/supervision_llamadas_20261125/$ARCHIVO" \
    -o "$PAQUETE/$ARCHIVO"
done

printf '%s  %s\n' \
  '37055ca1725b8906a237c774579dc0f9ad7588798044bf1eb57aa7f653e753f8' "$PAQUETE/deploy.php" \
  'f63740ef9d57192b24586fc5addd91a09de68eb39b4be6ce7846cfa5a36b9817' "$PAQUETE/manifest.json" \
  | sha256sum --check -

for ARCHIVO in \
  app/services/SupervisionLlamadasRepository.php \
  app/controllers/SupervisionLlamadasController.php \
  app/views/telefonia/supervision_llamadas.php \
  public/javascript/supervision_llamadas.js \
  public/css/supervision_llamadas.css
do
  DESTINO="$PAQUETE/production-candidate/$ARCHIVO"
  mkdir -p "$(dirname "$DESTINO")"
  curl --fail --silent --show-error --retry 2 "$BASE/$ARCHIVO" -o "$DESTINO"
done

php "$PAQUETE/deploy.php" --root="$SITIO" --backup-root="$PRIVADO" --check
if [ -L "$PRIVADO/supervision_llamadas_paquete_actual" ]; then
  printf '%s\n' 'El indicador de paquete no puede ser un enlace simbólico.' >&2
  exit 1
fi
printf '%s\n' "$PAQUETE" > "$PRIVADO/supervision_llamadas_paquete_actual"
printf '%s\n' 'CHECK completado. No se reemplazaron archivos del sitio.'
