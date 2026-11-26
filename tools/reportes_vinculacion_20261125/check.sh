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
for ARCHIVO in deploy.php manifest.json apply.sh rollback.sh conciliar_candidato.php
do
  curl --fail --silent --show-error --retry 2 "$BASE/tools/reportes_vinculacion_20261125/$ARCHIVO" -o "$PAQUETE/$ARCHIVO"
done
printf '%s  %s\n' \
  '3acded7e7531a86560fe5e4fdf9e560cc3da8d8dd61a0da9a9b965ecaa9551be' "$PAQUETE/deploy.php" \
  'f6fef7f2730e708f641d526585c118e361a3685dc273072596ed9f4a53a22be8' "$PAQUETE/manifest.json" \
  'a8b0277f9d22ccb39446fb5d969cac03b2875751274575957b98bf7ab1f57ca9' "$PAQUETE/apply.sh" \
  'e71e83ff58e8b60361470f9afafbda72432de9baaef24d3c210db1798e412b53' "$PAQUETE/rollback.sh" \
  '5903af003ef2e51591dce5da30662eae1e32ce225270c0770fc523a3b4bde834' "$PAQUETE/conciliar_candidato.php" | sha256sum --check -
for ARCHIVO in \
  app/services/SeguimientoReporteAnaliticaService.php \
  app/services/SeguimientoAtencionOperativaService.php \
  app/controllers/SeguimientoVinculacionReporteController.php \
  app/views/seguimiento_vinculacion/reportes.php \
  public/css/seguimiento_reportes_decisiones_v2.css \
  public/javascript/seguimiento_reportes_decisiones_v2.js \
  public/javascript/seguimiento_reportes_analitica.js \
  app/controllers/SeguimientoReporteAnaliticaController.php
do
  DESTINO="$PAQUETE/production-candidate/$ARCHIVO"
  mkdir -p "$(dirname "$DESTINO")"
  curl --fail --silent --show-error --retry 2 "$BASE/$ARCHIVO" -o "$DESTINO"
done
php "$PAQUETE/deploy.php" --root="$SITIO" --backup-root="$PRIVADO" --check
test ! -L "$PRIVADO/reportes_vinculacion_paquete_actual"
printf '%s\n' "$PAQUETE" > "$PRIVADO/reportes_vinculacion_paquete_actual"
printf '%s\n' 'CHECK completado. No se reemplazaron archivos del sitio.'
