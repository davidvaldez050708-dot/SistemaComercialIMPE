#!/usr/bin/env bash
set -eu
umask 077
test "$#" -eq 4
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
    "$BASE/tools/supervision_fechas_20261125/$ARCHIVO" -o "$PAQUETE/$ARCHIVO"
done
printf '%s  %s\n' \
  '37055ca1725b8906a237c774579dc0f9ad7588798044bf1eb57aa7f653e753f8' "$PAQUETE/deploy.php" \
  'c11b7dd6e05b4ce0c86956c9a5258fcd9ae2d2be0d1124fffb21a805d3566696' "$PAQUETE/manifest.json" \
  | sha256sum --check -
ARCHIVO="app/services/SupervisionLlamadasRepository.php"
DESTINO="$PAQUETE/production-candidate/$ARCHIVO"
mkdir -p "$(dirname "$DESTINO")"
curl --fail --silent --show-error --retry 2 "$BASE/$ARCHIVO" -o "$DESTINO"
php "$PAQUETE/deploy.php" --root="$SITIO" --backup-root="$PRIVADO" --check
test ! -L "$PRIVADO/supervision_fechas_paquete_actual"
printf '%s\n' "$PAQUETE" > "$PRIVADO/supervision_fechas_paquete_actual"
