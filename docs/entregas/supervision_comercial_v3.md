# Supervisión comercial con las vistas V3 compartidas

La entrada de Supervisión seguía usando `index.php` (kanban anterior) y no permitía consultar Expedientes cerrados. Esta entrega selecciona las mismas plantillas V3 del asesor para el embudo y las bandejas, conservando sus hojas de estilo.

## Comportamiento

- Cinco columnas compartidas: Nuevos, Contactados, Potenciales, Seguimiento y Proceso Inscripción. Se conserva la clasificación de códigos históricos de V3, sin modificar códigos en la base.
- Mismas tarjetas, colores, subetapas, intentos y citas V3. Supervisión añade el nombre del asesor y mantiene el filtro por asesor, con paginación independiente por columna.
- Cuatro pestañas: Embudo de ventas, Recuperación, Descartados y Expedientes cerrados. Conservan el asesor seleccionado durante la navegación de Supervisión.
- El menú de cada tarjeta ofrece Expediente comercial. La ficha comparte Resumen, Actividad y Llamadas; incluye movimientos, gestiones, programación y reprogramaciones. El resumen consulta la actividad V3 actual.
- Supervisión puede consultar expedientes cerrados de los asesores, incluido un asesor que únicamente conserve expedientes históricos.
- El administrador con `ventas.embudo.supervisar` permanece en consulta. No se habilitan botones de llamada, arrastre, actividades, inscripción, descartes ni movimientos. Los servicios de escritura siguen exigiendo rol de asesor y propiedad.
- Las llamadas consultadas pertenecen al asesor de la asignación. Este cambio no concede acceso a grabaciones ni modifica sus endpoints de autorización.
- Los asesores mantienen el aislamiento de su propia cartera. El permiso de supervisión no se hereda de `prospectos.ver_todos` ni de parámetros enviados por el navegador.

El controlador continúa ejecutando la devolución automática de Recuperación solo en la ruta del asesor V3. La consulta de Supervisión no ejecuta esa operación. Los parámetros explícitos de rescate del tablero anterior y de previsualización se conservan.

## Validación

`tests/supervision_comercial_v3_db.php` crea su propia base sintética en MariaDB local y la elimina al terminar. Comprueba varias carteras, filtro, más de veinte tarjetas, etapas antiguas, agenda V3, expediente activo/cerrado, llamadas por propietario, asesores con solo cierres históricos, escape de nombres y rechazos de acceso. Compara los contadores SQL de inserciones, actualizaciones, borrados y DDL antes/después de las lecturas: no se producen escrituras. También comprueba que el administrador no pueda registrar actividades aunque su sesión contenga permisos de operación.

`tests/supervision_comercial_v3_browser.cjs` utiliza las plantillas PHP renderizadas por esa prueba. Compara estilos calculados de las cinco columnas y tarjetas entre asesor y supervisor, comprueba cuatro pestañas, navegación al expediente, filtro por asesor y paginación sin recarga completa, ausencia de controles operativos y vistas de escritorio/móvil. No ejecuta llamadas ni utiliza información real.

Las pruebas reutilizables del publicador se ejecutan con `IMPE_PUBLICADOR_MODULO=publicar_supervision_comercial_v3_hostinger`: verifican respaldo, integridad, publicación idempotente, reversión normal y por fallo parcial, bloqueo de personalizaciones y conservación de la variante de telefonía Hostinger revisada.

Las pruebas antiguas de previsualización V1 contienen expectativas de interfaz anteriores y no se utilizan como aprobación de esta entrega.

## Entrega y publicación

`tools/publicar_supervision_comercial_v3_hostinger.py` publica seis archivos: repositorio, cuatro vistas y controlador. El controlador se publica al final, después de sus dependencias. No publica hojas de estilo, JavaScript, permisos, configuración ni migraciones.

El CHECK valida bases exactas y dependencias protegidas. También revisa mediante metadatos las tablas y columnas necesarias para las fichas y expedientes. Solo admite la versión de telefonía original y la variante exacta de Hostinger previamente revisada; conserva el servicio sin cambios. Una personalización desconocida bloquea la publicación.

Con el paquete extraído fuera de `public_html`:

```bash
SITIO="$HOME/domains/fundacionrededumex.org/public_html"
PUBLICADOR="$ENTREGA/tools/publicar_supervision_comercial_v3_hostinger.py"
python3 "$PUBLICADOR" --site "$SITIO" --stage "$ENTREGA" --check &&
python3 "$PUBLICADOR" --site "$SITIO" --stage "$ENTREGA" \
  --backup-root "$HOME/respaldos-supervision-comercial-v3" \
  --apply --confirm PUBLICAR_SEGUIMIENTO_ACCIONES_V3_IMPE &&
python3 "$PUBLICADOR" --site "$SITIO" --stage "$ENTREGA" --check
```

Se reutilizan el motor y las confirmaciones de publicación anteriores; sus mensajes genéricos pueden mencionar Gestión de Potenciales V3. El manifiesto solo contiene los seis archivos de esta entrega. El APPLY no ejecuta SQL de escritura ni modifica prospectos.

Para revertir el código, usar la ruta exacta `ROLLBACK_BACKUP` devuelta por esta publicación:

```bash
python3 "$PUBLICADOR" --site "$SITIO" --backup-dir "$RESPALDO_EXACTO" \
  --rollback --confirm REVERTIR_SEGUIMIENTO_ACCIONES_V3_IMPE
```

El rollback conserva los datos y bloquea si se personalizó posteriormente un archivo publicado. No utiliza respaldos de otras entregas.

## Prueba posterior

Con Administrador, abrir Gestión comercial de supervisión y comprobar las mismas cinco columnas de V3. Seleccionar asesor 22, consultar la actividad de una tarjeta ficticia desde Expediente comercial y recorrer Recuperación, Descartados y Expedientes cerrados. Confirmar que se conserve el filtro y que no haya controles de operación. Con el asesor 22, confirmar que siguen visibles únicamente sus asignaciones y que sus controles habituales permanecen disponibles.
