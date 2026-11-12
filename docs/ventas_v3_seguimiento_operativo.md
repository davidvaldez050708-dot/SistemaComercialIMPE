# Seguimiento Operativo V3

El paquete parte de los archivos publicados en el PR #258 y aplica seis archivos del tablero. El CHECK compara los blobs de esos archivos y de las dependencias protegidas. Acepta las dos variantes acreditadas del movimiento anterior. Una variante adicional requiere comparación antes de publicar.

El historial guarda las altas, reprogramaciones y cierres de actividades por asignación. Una asignación puede tener una actividad V3 pendiente a la vez. El tablero muestra la agenda anterior por separado y conserva su fecha al mover tarjetas. El historial V3 queda disponible para auditoría después de revertir los archivos.

## CHECK en Hostinger

Ejecutar en SSH. El CHECK lee los archivos del sitio y prepara el paquete fuera de public_html.

```bash
(
set -euo pipefail
umask 077
SITIO="$HOME/domains/fundacionrededumex.org/public_html"
D="$HOME/ventas-v3-seguimiento-7acc422"
BASE="https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/7acc422362f2f5907b1d699dc5061233f230f418"
mkdir -p "$D"
for archivo in \
  tools/publicar_ventas_v3_seguimiento_hostinger.py \
  tools/publicar_ventas_v3_gestion_potenciales_hostinger.py \
  database/migrations/2026_11_12_ventas_seguimiento_operativo_v3.sql \
  app/services/EmbudoComercialV3SeguimientoService.php \
  app/services/EmbudoComercialRepository.php \
  app/controllers/EmbudoComercialController.php \
  app/views/embudo_comercial/operativo_v3.php \
  public/css/embudo_v3_operativo.css \
  public/javascript/embudo_comercial_v3_operativo.js; do
  mkdir -p "$D/${archivo%/*}"
  curl -fsSL "$BASE/$archivo" -o "$D/$archivo"
done
python3 -B "$D/tools/publicar_ventas_v3_seguimiento_hostinger.py" \
  --site "$SITIO" --stage "$D" --check
)
```

## APPLY y ROLLBACK

Una vez aprobado el CHECK, respaldar la base y aplicar por separado la migración aditiva incluida en el paquete. Confirmar la creación de la tabla InnoDB con `SHOW CREATE TABLE prospecto_embudo_actividad_v3`. La tabla habilita las actividades del tablero. El publicador sólo gestiona los archivos.

```bash
SITIO="$HOME/domains/fundacionrededumex.org/public_html"
D="$HOME/ventas-v3-seguimiento-7acc422"
python3 -B "$D/tools/publicar_ventas_v3_seguimiento_hostinger.py" \
  --site "$SITIO" --stage "$D" --check && \
python3 -B "$D/tools/publicar_ventas_v3_seguimiento_hostinger.py" \
  --site "$SITIO" --stage "$D" \
  --backup-root "$HOME/respaldos-impe-seguimiento-v3" \
  --apply --confirm PUBLICAR_SEGUIMIENTO_V3_IMPE
```

Para revertir, usar la ruta exacta devuelta como `ROLLBACK_BACKUP`.

```bash
python3 -B "$D/tools/publicar_ventas_v3_seguimiento_hostinger.py" \
  --site "$SITIO" --backup-dir "$RESPALDO_EXACTO" \
  --rollback --confirm REVERTIR_SEGUIMIENTO_V3_IMPE
```
