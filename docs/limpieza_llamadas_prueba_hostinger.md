# Limpieza de pruebas de Recepción y Asesores

Paquete inmutable: `b8cf8ec97962dc671f027f5c5b1112712a4822bf`.

Alcance autorizado: toda la actividad de prueba existente de Recepción/Marketing (rol 7) y Asesores (rol 3), incluyendo entrantes, salientes y transferencias. Retira registros telefónicos, resultados, intentos, capturas por llamada, evidencia comercial almacenada, programaciones y avisos. Los contactos de la agenda se conservan; sólo se limpia su próxima llamada programada. No modifica usuarios, permisos, territorios, extensiones, datos de prospectos de otros módulos ni expedientes de Vinculación. No invoca la API de Zadarma ni elimina sus llamadas o grabaciones.

Una llamada puede compartir evidencias con un Analista u otro rol. En ese caso se conserva íntegro su registro técnico y sólo se excluye de las vistas de Recepción/Ventas. También se preservan las operaciones y grabaciones internas que necesita el Analista. Las llamadas exclusivas de Recepción/Ventas se retiran junto con sus eventos locales. Se conservan marcas mínimas de exclusión, sin teléfonos o nombres, para evitar que los datos vuelvan a aparecer por sincronización o eventos tardíos. Las llamadas nuevas siguen usando sus referencias nuevas normalmente.

## Ejecutar

Terminar las llamadas activas y cerrar las pestañas del CRM antes de ejecutar. No efectuar llamadas o editar agendas durante la limpieza. El bloque muestra el resumen y guarda un respaldo privado antes de publicar la protección o borrar filas. La autorización del usuario abarca la aplicación de este alcance; no requiere una nueva confirmación.

```bash
(
set -euo pipefail
umask 077
SITIO="$HOME/domains/fundacionrededumex.org/public_html"
PAQUETE="$HOME/impe-limpieza-llamadas-b8cf8ec9"
RESPALDOS="$HOME/respaldos-impe-limpieza-llamadas"
BASE="https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/b8cf8ec97962dc671f027f5c5b1112712a4822bf"
mkdir -p "$PAQUETE"
chmod 700 "$PAQUETE"
cd "$PAQUETE"
curl -fsSL "$BASE/tools/publicar_limpieza_llamadas.py" -o publicar_limpieza_llamadas.py
curl -fsSL "$BASE/tools/limpieza_llamadas_release.json" -o limpieza_llamadas_release.json
curl -fsSL "$BASE/tools/limpiar_llamadas_prueba.php" -o limpiar_llamadas_prueba.php
printf '%s\n' \
  '5311b45dd30b150be9891fcc4100c0502a7dfe001c2c9693add3d2459c9fe5c7  publicar_limpieza_llamadas.py' \
  'a8e151ee8f3b23a57e0856d7a6d7ffb117ac6133cf76a0a06ab413d675988351  limpieza_llamadas_release.json' \
  '6935e29f79af3be76473819f46635cb791bd22cbac5cc1b14c64d579433b851b  limpiar_llamadas_prueba.php' | sha256sum -c -
python3 publicar_limpieza_llamadas.py --site "$SITIO" --backup-root "$RESPALDOS/archivos" --check
php limpiar_llamadas_prueba.php --site "$SITIO" --backup-root "$RESPALDOS" --check > revision.json
cat revision.json
PLAN="$(python3 -c 'import json,sys;print(json.load(open(sys.argv[1],encoding="utf-8"))["plan_privado"])' revision.json)"
python3 publicar_limpieza_llamadas.py --site "$SITIO" --backup-root "$RESPALDOS/archivos" --apply
php limpiar_llamadas_prueba.php --site "$SITIO" --backup-root "$RESPALDOS" --apply "$PLAN"
)
```

La última línea de éxito debe decir **`APLICADO: registros de prueba retirados del CRM. Zadarma no se modificó.`** y mostrar el archivo de respaldo. El primer `APLICADO` del publicador sólo instala la protección; todavía no elimina datos. Después abrir el CRM nuevamente y recargar con Ctrl+F5. La campana se actualiza en su siguiente consulta.

`revision.json` sólo contiene cantidades, fecha de corte, huella y ruta del respaldo. No compartir el archivo comprimido del plan: contiene las filas originales para poder restaurarlas. Su carpeta tiene permisos 700 y el archivo 600.

## Detenciones seguras

Las comprobaciones rechazan versiones de archivos desconocidas, extensiones activas compartidas con otro rol, transferencias hacia estos usuarios originadas fuera del alcance, claves foráneas hacia las tablas a borrar, tablas sin transacciones y cambios de filas entre comprobación y aplicación. Nunca se desactivan claves foráneas ni se reinician los contadores de identidad. Si falla una operación de borrado se revierte la transacción completa. Una aplicación repetida del mismo plan no vuelve a borrar actividad nueva.

Si los datos cambiaron desde el plan, repetir la comprobación para crear otro respaldo. No eliminar manualmente tablas ni usar TRUNCATE como alternativa. Si aparece otro error, conservar la salida para revisar el alcance; no continuar con un borrado más amplio.

## Restaurar datos

Cerrar las pestañas de los operadores y sustituir la ruta de ejemplo por el `plan_privado` exacto impreso:

```bash
php "$HOME/impe-limpieza-llamadas-b8cf8ec9/limpiar_llamadas_prueba.php" \
  --site "$HOME/domains/fundacionrededumex.org/public_html" \
  --backup-root "$HOME/respaldos-impe-limpieza-llamadas" \
  --restore "$HOME/respaldos-impe-limpieza-llamadas/plan-ID-IMPRESO.json.gz"
```

Se verifican la huella del respaldo contra la limpieza aplicada, la base de datos y las estructuras. La restauración recupera las filas eliminadas, conserva las llamadas nuevas y rechaza claves ocupadas o programaciones modificadas posteriormente. No modifica Zadarma. El publicador permite además restaurar los siete archivos desde su respaldo independiente; restaurar primero los datos si se pretende volver al estado previo completo.

## Validación

SQL y CLI contra bases efímeras: exclusión global y por tramo, conservación de llamadas/avisos/operaciones de Analista, eventos tardíos compartidos, no reaparición al sincronizar, llamadas nuevas, contactos conservados, recuperación y conflictos. Publicación probada con LF/CRLF y la variante personalizada de notificaciones de Hostinger. Se mantienen las comprobaciones de Recepción, Ventas, reportes y supervisión con audios; no se hicieron llamadas reales ni se eliminaron datos de producción durante estas pruebas.
