# Campañas y programas por formulario

La pestaña pasa a llamarse **Campañas**, con título **Prospectos de campañas**. Se conservan `seccion=meta`, `META_ADS`, los identificadores originales y `prospectos.importar_meta`. La base de esta entrega es `044175f7` (PR #198), que ya conserva la corrección BOM y las 1,271 filas válidas del archivo real. El PR #191 se sincroniza con esta línea; no se vuelve al importador inicial.

## Consulta

En Campañas, «Campaña» sustituye a «Procedencia», con los nombres originales de `prospecto_origenes.campania_nombre` (el `campaign_name` importado). El filtro usa `campania_id` real y combina búsqueda, programa y paginación. Campaña y programa se comprueban contra la misma procedencia, evitando coincidencias cruzadas en personas con varios orígenes.

Todos conserva ocho columnas y Procedencia; un detalle desplegable muestra las campañas asociadas y sus IDs. Campañas también permite desplegar todas las procedencias si hay varias. Los nombres se escapan y se consultan en una única lectura adicional por página, sin `GROUP_CONCAT` truncable ni consulta por fila. Los registros sin campaña muestran «—». La ficha lateral y Aliados conservan sus rutas y funcionamiento.

## Configuración autorizada

El permiso nuevo **Prospectos → Configurar formularios de campañas** (`prospectos.configurar_formularios`) permite administrar asociaciones y utilizar excepciones avanzadas al importar. Requiere `prospectos.ver_todos`; no concede `prospectos.importar_meta`. La configuración es administrativa, por lo que Administración puede usarla conservando su política que impide operar importaciones. Otros roles necesitan concesión explícita desde Roles y Permisos y renovar su sesión.

En Campañas → Configurar formularios:

1. Usar **Detectar formularios** con el archivo original, o elegir un formulario ya observado en el historial/vista previa.
2. La detección sólo conserva IDs, nombres y cantidades de formularios en la sesión. No importa leads ni cambia asociaciones. No se admiten IDs arbitrarios enviados directamente por POST.
3. Seleccionar el programa real del catálogo y pulsar **Guardar asociación**.
4. Titulacion Exp se reconoce por su ID real `f:1132366336136623`, no por el nombre. Para Bachillerato se debe detectar primero su ID del archivo correspondiente: esta entrega no inventa ese ID ni crea programas.

Cada modificación inserta una versión en `prospecto_formulario_programas`, con programa, usuario y hora UTC (presentada en México). Las asociaciones confirmadas no se cambian por detecciones posteriores o por una selección realizada durante la importación. Una ventana con versión anterior no puede sobrescribir una actualización reciente. Retirar una asociación conserva su historial; volver a elegir un programa crea otra versión.

## Importación normal

El usuario selecciona el archivo y revisa una sola selección por ID de formulario. Si tiene asociación vigente, el programa aparece seleccionado. Si es desconocido, debe elegirlo antes de confirmar; el servidor también lo exige. La asociación no deduce programas por nombres de formulario/campaña ni por ubicación.

La procedencia de cada campaña se conserva dentro de un desplegable cerrado. Los selectores y casillas excepcionales sólo existen para usuarios con permiso de configuración y permanecen dentro de **Opciones avanzadas**, cerrado por defecto. Una contradicción Bachillerato/Titulación avisa sin reclasificar automáticamente.

Los programas se guardan en `prospecto_intereses` vinculados a su procedencia. Éste será el criterio para la futura elegibilidad por programa. No se implementan todavía distribución, jornadas, cargas ni disponibilidad. Los cambios de asociación no actualizan prospectos ya incorporados. Las cargas iniciadas conservan su clasificación congelada y sus lotes de 75; los reintentos no duplican datos.

## Publicación controlada

No se ejecutó SQL ni se publicaron archivos en Hostinger durante el desarrollo. Se probaron exclusivamente fixtures ficticios locales y de CI. El archivo real sólo se leyó con el parser, sin incorporarlo.

`publicar_prospectos_campanias.py` y `prospectos_campanias_release.json` actualizan dieciséis archivos. El publicador es offline y no ejecuta SQL. Verifica hashes de la versión actualmente instalada, sintaxis, dependencias y la política de supervisión. Preserva la variante de `RolModel.php` de Hostinger mediante un payload revisado que añade únicamente el permiso y su dependencia. Rechaza variantes desconocidas, guarda respaldo privado y admite rollback, LF/CRLF e instalación idempotente.

Descargar ambos archivos del commit indicado en la entrega y verificar sus SHA-256. Después:

```sh
SITIO="$HOME/domains/fundacionrededumex.org/public_html"
RESPALDOS="$HOME/respaldos-impe-campanias"
python3 publicar_prospectos_campanias.py --site "$SITIO" --backup-root "$RESPALDOS" --check
# Aplicar sólo tras revisión compatible:
python3 publicar_prospectos_campanias.py --site "$SITIO" --backup-root "$RESPALDOS" --apply
```

La persistencia requiere una tabla adicional de configuración. El publicador de código no la crea. La preparación explícita es independiente:

```sh
php "$SITIO/tools/prospectos_formularios_programas.php" --site "$SITIO" --check
# Paso manual separado: crea únicamente una tabla vacía; no actualiza datos históricos.
php "$SITIO/tools/prospectos_formularios_programas.php" --site "$SITIO" --backup-root "$RESPALDOS" --apply
```

La comprobación es de sólo lectura. La aplicación rechaza estructuras incompatibles y no altera una tabla ya instalada; guarda constancia privada de su estado previo. No se realiza una conciliación ni una importación de registros existentes. Sin esa preparación, el importador sigue permitiendo seleccionar programas por archivo y la administración informa que falta la estructura persistente.

Para revertir el código, usar la ruta RESPALDO impresa por el publicador:

```sh
python3 publicar_prospectos_campanias.py --site "$SITIO" --restore "/ruta/RESPALDO/impresa"
```

La restauración bloquea cambios posteriores y no elimina la tabla de configuración ni su historial. Una asociación se corrige desde la interfaz mediante una nueva versión; nunca se necesita borrar historial o reclasificar prospectos.

## Pruebas

Se comprueban el filtro combinado, paginación, campañas múltiples, nombres largos/XSS, consultas constantes para una o cien filas, detección sin importación, asociación por ID aunque coincidan los nombres, permisos independientes, catálogo, concurrencia optimista, historial/retirada/restauración, selección automática, programa obligatorio, excepciones autorizadas, BOM, deduplicación, lotes, rollback y reanudación. Se conservan las regresiones de Aliados, formularios y horario de contacto.

La publicación se prueba sobre copias canónicas y de la variante exacta de Hostinger, con LF/CRLF, conflictos, dependencia distinta, fallos de escritura y restauración. También se prueba el modelo de permisos personalizado de Hostinger contra una base sintética. El paquete anterior del PR #198 conserva sus bytes y se verifica contra su revisión histórica, para impedir que un publicador viejo instale código más reciente sin revisión.

Validación real sin guardar: detectar formularios para revisar su ID; configurar el programa con permiso; en otra sesión autorizada seleccionar el archivo y **Revisar archivo**. Debe mostrar 1,271 importables, un formulario y nueve campañas, con el programa ya seleccionado. No pulsar **Confirmar importación** durante esa comprobación; descartar la vista previa al terminar.
