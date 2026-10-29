# Consulta de uno o dos resultados: etapa 2

Los resultados ya se guardan desde el selector único. Esta entrega hace visibles todas las selecciones en el historial propio, Supervisión, Reportes y sus exportaciones, conservando el diseño de cada módulo.

- El historial del asesor presenta una etiqueta por resultado.
- Supervisión muestra ambos resultados en la tarjeta y en el detalle. Su filtro encuentra la llamada por cualquiera de ellos. Las etiquetas largas se ajustan al ancho de la tarjeta en móvil.
- Reportes permite filtrar por cualquiera de los resultados. La distribución cuenta las llamadas que contienen cada resultado; una llamada con dos selecciones pertenece a dos categorías, pero sigue siendo una sola llamada en los totales, tasas, duración y ranking.
- El PDF de Reportes conserva todas las etiquetas en el detalle y la distribución. El PDF de Desempeño incorpora la misma distribución después del historial diario, sin mover el ranking ni cambiar sus criterios.
- Los registros anteriores conservan sus etiquetas, observaciones y significado. La versión anterior que guardó un resultado más WhatsApp y SMS conserva sus tres datos, aunque la captura actual permita hasta dos selecciones.
- Se mantiene la protección local `IMPE_VENTAS_GUARD_RESULTADO_V3` del botón del historial.

Las consultas por lotes comprueban conjuntamente ID de resultado, propietario, extensión y referencia PBX. Cuenta Clave conserva únicamente su equipo; Administrador y Dirección conservan su alcance autorizado. No se usan números telefónicos para enlazar resultados.

La publicación no ejecuta SQL, no migra registros y no modifica los motores de llamada, formularios de captura, grabaciones, horarios, permisos, metas o jornadas. Las metas, auditoría de efectividad y primera/última llamada corresponden a entregas posteriores.

## Comprobaciones

- `tests/ventas_consulta_doble_db.py`: MariaDB desechable en localhost, conexión de producción bloqueada. Lectura de históricos, selección doble, filtros por segundo resultado, conteo único, ausencia de escrituras, identidad exacta y acceso por roles/equipos.
- `tests/ventas_consulta_doble_qa.py`: vistas reales con datos sintéticos en Chrome, escritorio y móvil; PDFs reales con Dompdf y CSS del sistema. Los PDFs se renderizaron con Poppler y se revisaron visualmente.
- `tests/publicacion_ventas_consulta_doble_test.py`: versiones exactas, LF/CRLF, rechazo de variantes desconocidas, comprobación sin escrituras, respaldo privado, aplicación repetible y restauración exacta. Comprueba que audio y archivos del flujo/captura permanezcan intactos.

## Publicación

Distribuir `tools/publicar_ventas_consulta_doble.py` junto con `tools/ventas_consulta_doble_release.json` de un mismo commit. El manifiesto contiene las versiones identificadas de Hostinger y sus reemplazos; incluso los archivos nuevos en esta rama deben existir en el sitio con el hash comprobado.

1. Cerrar las pestañas del CRM y esperar a que no existan llamadas activas.
2. Ejecutar el publicador con `--check`, el directorio del sitio y un `--backup-root` privado fuera de `public_html`.
3. Si todos son compatibles o actualizados, ejecutar `--apply` y repetir `--check`.
4. Si aparece una versión distinta, el publicador se detiene antes de escribir. Conservar esa versión y conciliarla; no omitir la comprobación.
5. Para restaurar, usar el mismo publicador y manifiesto con `--restore RUTA_RESPALDO`. Se bloquea si encuentra una edición posterior.

## Pruebas en Hostinger

Consultar una llamada con «Prospecto potencial» y «Se envió por WhatsApp»: deben aparecer ambos en Mis llamadas, Supervisión y el detalle del reporte. Buscarla por cualquiera de esos resultados, exportar el mismo corte y comprobar que conserva ambas etiquetas. Al seleccionar sólo esa llamada, los totales deben contar una llamada. Verificar también un registro anterior y una cuenta de otro equipo: no se deben modificar los históricos ni ampliar su acceso.
