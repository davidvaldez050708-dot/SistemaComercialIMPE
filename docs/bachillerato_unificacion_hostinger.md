# Unificar Bachillerato General

PR #124, rama `feat/aliados-programas-registro`. El usuario confirmó que el programa antes llamado Bachillerato 286 es el mismo que Bachillerato General. La identidad canónica es `bachillerato-general`, nombre visible **Bachillerato General**. No se fusiona Bachillerato en 2 años ni ningún otro programa.

## Alcance del escaneo y cambio

Se revisaron los controladores de formularios internos y públicos, catálogo y edición de programas de aliados, Convocatorias, modelos de persistencia, reportes HTML/PDF, semillas y referencias SQL. Hostinger confirmó dos entradas activas y sus cuatro claves foráneas al catálogo. No existe otro catálogo de programas en la estructura comunicada.

Los formularios generales y exclusivos, alta/edición, directorios, editor multiprograma y reportes muestran una sola opción. Las solicitudes y URLs anteriores se normalizan en un helper compartido y se vuelven a autorizar contra el aliado. Enviar ambos alias en un lote se rechaza; seleccionar ambos en la configuración del aliado produce una sola asignación. Las imágenes conservan su asociación y no se habilitan programas ajenos.

El código anterior permanece únicamente como alias técnico de entrada y en la migración/pruebas para conservar enlaces e idempotencia. No se mantiene como opción del catálogo. Los respaldos y paquetes históricos inmutables conservan sus datos para poder restaurar. La referencia legal **SEP · Acuerdo 286** y sus logos no son una opción educativa duplicada y no se alteran.

## Base de datos: migración explícita, nunca automática

| Tabla | Cambio |
|---|---|
| `formulario_programas` | Conservar/crear General, unir actividad y retirar la entrada anterior después de mover sus referencias. |
| `formulario_aliado_programas` | Trasladar autorizaciones y fusionar sólo la asignación duplicada del mismo aliado. |
| `formulario_aliado_procedencias` | Unificar código, conservando ID, registro, origen y claves de deduplicación anteriores. |
| `formulario_aliado_convocatorias` | Conservar referencias, formulario, tokens y convocatoria; unificar código. |
| `marketing_aliado_manual_convocatorias` | Conservar aliado y convocatoria; unificar código. |
| `marketing_aliado_convocatorias_origen` | Conservar ID, origen y creador; unificar código. |
| `formulario_registros` | Normalizar el perfil de interés, conservando los prospectos. |
| `formulario_inscripciones` | Normalizar el perfil, conservando las inscripciones. |
| `convocatorias` | Unificar subtipo y nombre del programa en títulos; conservar IDs, imágenes, fechas y enlaces. |
| `aliados_convocatorias_envios` | Normalizar el título del programa; conservar envío, destinatario y contenido original del mensaje. |

`tools/unificar_bachillerato_general.php --check` sólo consulta. `--apply` exige directorio privado y guarda un diario JSON con filas anteriores y posteriores, en modo 0600. No ejecuta DDL ni desactiva claves foráneas. Todas las modificaciones usan una transacción; una relación adicional no revisada, motor incompatible o conflicto impiden continuar. Un segundo apply no cambia nada.

La restauración verifica que no hubo modificaciones posteriores en las filas del programa; si las hay, se detiene para no perder nuevas capturas. Revierte sólo el diario de esta migración, sin vaciar tablas ni borrar otros programas.

## Publicación por fases

Usar revisión inmutable y hashes entregados en la conversación. Descargar a `$PREP` privado:

- `tools/publicar_bachillerato_unificado.py` como `publicar.py`.
- `tools/publicar_aliados_registro.py` con su mismo nombre.
- `tools/bachillerato_unificacion_release.json` como `entrega.json`.

El paquete revisa 17 archivos, instala primero el helper requerido y conserva las personalizaciones compatibles. Acepta la Etapa 3 instalada, con o sin el parche previo de clasificación General. Prueba ambas bases revisadas y sólo acepta un resultado único que conserve General y elimine la opción retirada; una combinación ambigua se bloquea. Incluye variantes exactas del modelo internacional PR114/V3 y de los formularios internacionales para preservar conexión, ubicación libre, callback y transacciones. La deduplicación conserva las claves anteriores y su ámbito por convocatoria: dos convocatorias diferentes no se mezclan. No reemplaza el modal modular ni el layout.

1. Ejecutar el publicador con `--check`, revisar diferencias y exigir REVISIÓN COMPATIBLE. No ejecutar `git pull` ni sobrescribir un conflicto.
2. Publicar los archivos con `--apply`, conservar su ruta de respaldo y ejecutar la comprobación SQL de abajo. Esta fase todavía no unifica la BD.
3. Si el check SQL termina sin errores, ejecutar la migración explícita y comprobar ambos resultados.

```bash
php "$SITIO/tools/unificar_bachillerato_general.php" --check
```

Sólo después de revisar las acciones pendientes:

```bash
RESPALDOS_SQL="$HOME/respaldos-impe-bachillerato-sql"
mkdir -p -m 700 "$RESPALDOS_SQL" &&
php "$SITIO/tools/unificar_bachillerato_general.php" --apply --backup-dir="$RESPALDOS_SQL" &&
php "$SITIO/tools/unificar_bachillerato_general.php" --check
```

Se espera `OK: un único Bachillerato General`. El publicador de archivos debe indicar cero pendientes al repetir `--check`.

## Validación funcional

Recargar sin caché. En Aliados → Programas y edición debe existir una sola casilla General, con la autorización previa conservada. Comprobar IQJ, un aliado de Vinculación y uno nuevo; una sola tarjeta General en Nueva convocatoria. Verificar filtros y edición de publicaciones antiguas, nombre de perfiles en Prospectos/Registro/Inscripción, reportes HTML/PDF y un enlace antiguo. No repetir altas reales sólo para verificar deduplicación.

Pruebas locales: 15 comprobaciones de identidad y escaneo activo; 24 casos SQL de migración/restauración; siete variantes de catálogo; 14 comprobaciones del servicio internacional entregado con SQL real; 118 comprobaciones V3 con guardado real, alias e imágenes; 65 de Aliados/Registro en BD; 60 de navegador/HTTP/concurrencia; permisos territoriales y publicación reversible sobre variantes compatibles. No se ejecutó SQL ni despliegue en Hostinger desde Codex.

## Restauración opcional: no es un paso de instalación

Si hay que deshacer ambas fases, restaurar primero la BD con la ruta JSON exacta emitida por la migración y después los archivos con el respaldo del publicador. Conservar `$PREP` de esta entrega. Nunca pegar las rutas de ejemplo sin sustituir.

```bash
RESPALDO_SQL="/ruta/real/emitida/por/la/migracion.json"
php "$SITIO/tools/unificar_bachillerato_general.php" --restore="$RESPALDO_SQL"
```

Sólo si la restauración SQL terminó correctamente:

```bash
RESPALDO_ARCHIVOS="/ruta/real/emitida/por/el/publicador"
python3 "$PREP/publicar.py" --site "$SITIO" \
 --manifest "$PREP/entrega.json" --backup-root "$HOME/respaldos-impe-bachillerato" \
 --restore "$RESPALDO_ARCHIVOS"
```

Si sólo se publicaron archivos y no se aplicó SQL, omitir la restauración SQL. Ante cambios posteriores o conflicto, compartir el mensaje técnico; no forzar el respaldo ni sustituir tablas completas.
