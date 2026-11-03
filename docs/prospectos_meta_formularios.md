# Meta Ads: BOM y clasificación por formulario

Esta entrega continúa `fix/meta-selector-csv` (7aad6c9), que contiene las correcciones anteriores de permisos y compatibilidad con Roles de Hostinger. No sustituye ese modelo ni sus políticas personalizadas.

## Comportamiento

El lector eliminaba el BOM mediante una expresión con `/u` y tres escapes de bytes: no reconocía U+FEFF como un solo carácter y rechazaba el primer ID. Ahora elimina únicamente secuencias BOM iniciales, antes de validar el campo. Conserva caracteres interiores, el ID original con su prefijo `l:` en la referencia de procedencia y el identificador canónico para deduplicar.

Se elige el programa del catálogo real por `form_id`. Sin ID se agrupa por nombre informado; sin ambos se muestra un grupo sin formulario. Las campañas se conservan individualmente. La excepción marcada para una campaña gana al formulario, incluso cuando indica explícitamente «Pendiente de clasificar». Sin excepción, cada registro hereda su formulario, aunque una campaña utilice varios formularios. No se infieren programas por títulos, ciudades o estados.

La advertencia Bachillerato/Titulación sólo solicita revisión. No cambia la selección ni bloquea la carga. Los errores indican fila y campo, sin reproducir valores personales. La autorización sigue usando `prospectos.ver_todos` y `prospectos.importar_meta`, con la política protegida de Administración existente.

La primera solicitud válida congela la clasificación. Se conserva al reanudar tras un error. Una carga antigua iniciada con selección por campaña mantiene ese mapa; una vista previa antigua todavía sin confirmar admite el nuevo formulario. Los lotes siguen siendo de 75 filas con transacción, deduplicación por ID y recuperación. No se fusionan teléfonos ni se asignan asesores.

## Validación

El archivo real se leyó localmente, sin conexión a BD: **1,271 filas, 1,271 válidas, cero rechazadas, cero repetidas, un formulario, nueve campañas y una advertencia**. No se incluyó el archivo ni sus datos personales en Git o CI.

Las pruebas usan datos ficticios: UTF16LE/BE con BOM de campo y encabezado, UTF8, Windows1252, XLSX, validación por campo, agrupación, catálogo, prioridad y pendientes, acceso directo sin permiso, CSRF, vista previa sin escrituras, deduplicación histórica y repetida, 151 registros en tres lotes, fallo y rollback del segundo lote, reanudación congelada, fechas/ID/procedencia, teclado y vista móvil. También continúan las regresiones de almacén, Aliados, formularios y horario de contacto.

Comandos principales:

```sh
php tests/prospectos_meta_archivo_unit.php
php tests/prospectos_meta_clasificacion_unit.php
php tests/prospectos_meta_permisos_unit.php
php tests/horario_contacto_unit.php
python3 tests/prospectos_meta_formularios_publicacion_test.py
# Con APP_ENV=testing y una BD local desechable impe_test_prospectos_*:
node tests/prospectos_central_browser.cjs
```

## Publicación controlada

Descargar del commit validado `tools/publicar_prospectos_meta_formularios.py` y `tools/prospectos_meta_formularios_release.json` en una carpeta privada, uno junto al otro. La entrega final indica la revisión inmutable y sus hashes. No reutilizar publicadores anteriores sobre esta versión.

El paquete contiene exclusivamente el nuevo helper de clasificación y las seis versiones del importador comprobadas. Acepta sus versiones conocidas o la entrega ya instalada, con normalización LF/CRLF. Rechaza variantes desconocidas antes de escribir. Valida sintaxis PHP, mantiene respaldo fuera del sitio, revierte un fallo de escritura y permite restauración. No tiene acceso de red ni ejecuta SQL. Los tres archivos de políticas/roles y el módulo de Aliados quedan fuera del paquete.

```sh
SITIO="$HOME/domains/fundacionrededumex.org/public_html"
python3 publicar_prospectos_meta_formularios.py \
  --site "$SITIO" --backup-root "$HOME/respaldos-impe-meta-formularios" --check
# Sólo después de CHECK compatible:
python3 publicar_prospectos_meta_formularios.py \
  --site "$SITIO" --backup-root "$HOME/respaldos-impe-meta-formularios" --apply
```

Conservar la ruta RESPALDO que imprime. Para revertir los siete archivos:

```sh
python3 publicar_prospectos_meta_formularios.py --site "$SITIO" \
  --restore "/ruta/RESPALDO/impresa"
```

La restauración rechaza cambios posteriores y no deshace importaciones de datos: esta entrega no migra ni elimina registros.

## Comprobación real sin guardar

En Prospectos → Meta Ads → Importar archivo, seleccionar el archivo original y pulsar **Revisar archivo**. Debe indicar 1,271 importables, cero descartados y un formulario «Titulacion Exp» con 1,271 registros. Elegir **Titulación por Experiencia** del catálogo una sola vez. Abrir «Campañas detectadas» para revisar las nueve y la advertencia de MANUEL P BACHILLERATO (dos registros). Es posible marcar una excepción para esa campaña, pero no se decide automáticamente.

No pulsar **Confirmar importación** en esta comprobación. **Descartar vista previa** elimina únicamente la preparación temporal. La estimación «Ya en CRM» depende de los registros existentes y se revisa de nuevo al confirmar.
