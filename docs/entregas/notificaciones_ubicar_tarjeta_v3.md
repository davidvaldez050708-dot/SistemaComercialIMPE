# Notificaciones comerciales: ubicación de tarjeta

Las tareas comerciales de la campana y sus toasts enlazan a `embudoComercial/ubicar`, con la asignación exacta y el ciclo de programación. El GET autentica la sesión, exige el permiso comercial y la habilitación de V3, consulta el estado vigente y responde con una redirección 303. No completa ni reprograma actividades.

La ubicación ignora los filtros del tablero anterior para que una búsqueda, programa o «Solo vencidos» no oculten la tarjeta. Abre la página correcta de la columna, conservando el resto del tablero. La consulta de posición usa el mismo orden que el listado mediante `ROW_NUMBER`; el CHECK comprueba su compatibilidad. También resuelve Recuperación, Descartados y Expedientes cerrados con sus permisos y orden respectivos. No reutiliza asignaciones históricas ni revela datos de otros asesores.

La tarjeta recibe foco y se desplaza hasta quedar visible, incluidos los contenedores con desplazamiento interno. Tiene borde y fondo institucionales durante 10 segundos. El contexto de resaltado se consume mediante `replaceState`, sin perder la página, y no se arrastra a filtros o recargas. El nombre continúa abriendo la ficha.

Si cambió el ciclo, informa de la reprogramación; si no hay fecha pendiente, indica que el aviso ya no está pendiente. Si cambió de sección, muestra su ubicación vigente. Una carrera entre la consulta y el render informa que debe reabrirse el aviso. Estos mensajes no certifican que una llamada o un pago se hayan realizado.

## Alcance

Se publican nueve archivos. Los proveedores de Agenda, recordatorios del Analista, telefonía, encabezado y pie permanecen intactos. No hay migraciones SQL. Las notificaciones ya abiertas se actualizan con la siguiente consulta normal del proveedor; una ficha que se abrió antes de la publicación sigue siendo una ficha.

## Comprobaciones

- `tests/supervision_comercial_v3_db.php`: MariaDB desechable, posición de 21 tarjetas sobre dos páginas comparada con el listado real, etapas, cierres, actividad vigente, permisos, GET HTTP real con 303, validación de entradas y rechazo de POST. Verifica ausencia de escrituras en las consultas.
- `tests/comercial_recordatorios_v3_db.php`: proveedor y endpoint reales con 80+ tareas, anticipaciones existentes, tipo, motivo, enlace con asignación/ciclo, reprogramación y finalización.
- `tests/notificacion_destino_browser.cjs`: navegador real, foco, visibilidad con scroll interno/externo, diez segundos de resaltado, página conservada y contexto consumido, recarga, tarjeta ausente y ninguna escritura.
- `tests/publicar_notificacion_destino_test.py`: bases exactas de la entrega anterior y variante Hostinger, metadatos reales cuando se ejecuta con `IMPE_DESTINO_PUBLICACION_TEST=YES` desde la prueba de supervisión. CHECK/APPLY/ROLLBACK, protección de personalizaciones, idempotencia, integridad de respaldo y reversión automática de fallo parcial. Agenda conservada byte por byte.

## Publicación selectiva

Usar `tools/publicar_ubicar_notificaciones_v3_hostinger.py` con el sitio y stage privados. Admite `--check`; `--apply --backup-root RUTA --confirm PUBLICAR_UBICAR_NOTIFICACIONES_V3_IMPE`; y `--rollback --backup-dir RESPALDO_EXACTO --confirm REVERTIR_UBICAR_NOTIFICACIONES_V3_IMPE`.

El CHECK bloquea ante bases o dependencias desconocidas y no realiza escrituras SQL. APPLY conserva un respaldo privado verificado del código; el motor restaura automáticamente si falla parcialmente. ROLLBACK no sobrescribe personalizaciones posteriores y elimina únicamente los tres archivos nuevos pertenecientes a esta entrega. Conservar la ruta `ROLLBACK_BACKUP` y la carpeta de entrega para ejecutar la reversión.

Después de aplicar, recargar una vez y abrir una actividad desde la campana: debe aparecer en la sección correcta con la tarjeta resaltada. Abrir la notificación mantiene la actividad pendiente. Reprogramar y completar se siguen realizando mediante las acciones comerciales existentes.
