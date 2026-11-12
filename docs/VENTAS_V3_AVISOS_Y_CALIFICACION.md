# Gestión Comercial V3 · avisos y calificación

La rama parte de `d63be666d5c30353bfd7edf81331b9a9b6ccd2d6`, la referencia conciliada. El puente de resultados conserva el blob `f03f8da725dfe3645db9ce9e1a76e11c38c81427`. El servicio de movimientos anteriores tiene una variante instalada conocida, por lo que la nueva calificación usa un servicio propio y ambos archivos quedan fuera del paquete.

## Comportamiento probado

- **Avisos.** Potenciales y movimientos comparten el toast institucional. La marca temporal por cuenta y pestaña se consume una sola vez. Con almacenamiento bloqueado se muestra el aviso antes de recargar. Si comienza una llamada durante el guardado, el aviso aparece en el tablero actual y la recarga se deja para después de la llamada.
- **Calificación.** Contactados muestra Potenciales como destino cuando existe una conversación acreditada de esa asignación. Arrastre y botón abren el mismo formulario. El asesor confirma la intención de inscribirse y documenta el motivo. En una transacción, el servidor comprueba permisos, propiedad, vigencia, etapa y evidencia, guarda `POTENCIAL / CALIFICADO` y registra ambas entradas de historial. La agenda y el historial telefónico se conservan.
- **Históricos.** Un `INTERESADO` se muestra y se gestiona en Potenciales conservando su código. El cambio visual no produce un movimiento SQL.

Pasaron siete escenarios en Chromium con Bootstrap, el render institucional, la integración en MariaDB sintética, el rechazo de POST por permisos y CSRF, y las pruebas unitarias del planificador V4 y de la política propuesta. En MariaDB se verificaron asignación ajena o revocada, ausencia de evidencia, falta de confirmación, permiso revocado y reintento con etapa obsoleta. Los publicadores pasaron CHECK, APPLY, verificación, ROLLBACK y bloqueo de personalización en copias locales.

## CHECK en Hostinger

Ejecutar primero la etapa de avisos desde SSH. El paquete de avisos se fija a `9a24c29`; modifica únicamente el JavaScript del tablero.

```bash
(
set -euo pipefail
umask 077
DIR="$HOME/ventas-v3-aviso-9a24c29"
BASE="https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/9a24c2965c6015a3a8ceb258f112afac13ab045d"
mkdir -p "$DIR/public/javascript"
for archivo in tools/publicar_ventas_v3_toast_hostinger.py tools/publicar_embudo_refresco_automatico_hostinger_v1.py; do
  curl -fsSL "$BASE/$archivo" -o "$DIR/${archivo##*/}"
done
curl -fsSL "$BASE/public/javascript/embudo_comercial_v3_operativo.js" \
  -o "$DIR/public/javascript/embudo_comercial_v3_operativo.js"
python3 -B "$DIR/publicar_ventas_v3_toast_hostinger.py" \
  --site "$HOME/domains/fundacionrededumex.org/public_html" --source-dir "$DIR" --check
)
```

La calificación usa el paquete de `563dd7d` y espera el JavaScript de la etapa de avisos instalado. Ejecutar su CHECK después de la verificación de esa etapa.

```bash
(
set -euo pipefail
umask 077
DIR="$HOME/ventas-v3-calificacion-563dd7d"
BASE="https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/563dd7dfcc4a1381112a29da70fc589233470816"
mkdir -p "$DIR"
for archivo in tools/publicar_ventas_v3_calificacion_hostinger.py tools/publicar_ventas_v3_gestion_potenciales_hostinger.py; do
  curl -fsSL "$BASE/$archivo" -o "$DIR/${archivo##*/}"
done
for archivo in \
  app/services/EmbudoComercialV3CalificacionService.php \
  app/services/EmbudoComercialV3MovimientosPermitidos.php \
  app/services/EmbudoComercialRepository.php \
  app/controllers/EmbudoComercialController.php \
  public/css/embudo_v3_operativo.css \
  public/javascript/embudo_comercial_v3_operativo.js \
  app/views/embudo_comercial/operativo_v3.php; do
  mkdir -p "$DIR/${archivo%/*}"
  curl -fsSL "$BASE/$archivo" -o "$DIR/$archivo"
done
python3 -B "$DIR/publicar_ventas_v3_calificacion_hostinger.py" \
  --site "$HOME/domains/fundacionrededumex.org/public_html" --stage "$DIR" --check
)
```

Los CHECK verifican blobs Git y archivos protegidos. Se valida sintaxis PHP cuando corresponde y sintaxis JavaScript si Node está disponible. El respaldo privado, la verificación posterior y el rollback forman parte de ambos publicadores. Antes de autorizar la calificación, hay que confirmar en el entorno objetivo que siguen instaladas las tablas InnoDB de etapas, movimientos, subetapas e historial y las tablas de vínculos y resultados PBX. El CHECK de archivos no inspecciona el esquema.

## Continuación

Quedan pendientes las gestiones transversales completas de Contactados y Seguimiento, el cierre de tareas vencidas, la vinculación autorizada con un trámite institucional real, Recuperación, Descartados y la integración posterior con V4. La referencia V3 contiene el catálogo de eventos institucionales, pero aún falta acreditar el vínculo entre la asignación, el registro institucional de inscripción y la verificación del pago. Las transiciones hacia esos estados permanecen bloqueadas en este paquete.
