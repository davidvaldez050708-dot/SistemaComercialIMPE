# Presentación de Reportes de asesores

La vista reutiliza el componente `table users-table` y los encabezados `table-panel-header` que usan Seguimiento y Aliados. No carga las hojas de esos módulos ni modifica sus componentes globales.

- Encabezados de bloque con título, contexto y separación antes de tablas o gráficos. Las descripciones dejan al menos 16 px hasta la tabla, tanto en escritorio como en móvil.
- Tablas de actividad y detalle integradas al panel, con la tipografía, relleno de celdas, fondo de encabezados y separadores de las tablas comunes del sistema.
- Valores numéricos alineados y con cifras tabulares; avatares conservados. Las tablas extensas se desplazan dentro de su región en pantallas pequeñas.
- Resultados de una misma llamada presentados como etiquetas separadas; se conservan todas las selecciones y aclaraciones históricas. Observaciones y teléfonos conservan los mismos datos y límites de presentación.
- Distribución de resultados con contadores y tarjetas delimitadas. Gráfica, leyenda y tabla diaria con separación propia.
- Pie de tabla con cantidad de registros y página actual. Anterior/Siguiente aparecen inactivos cuando ya se está en el extremo correspondiente; las URLs y el tamaño de página siguen siendo los existentes.
- El resumen de pendientes se armoniza mediante un contenedor exclusivo del reporte. Su plantilla compartida y su presentación en Desempeño no se modifican.

El alcance es de presentación. No cambian métricas, consultas, periodos, alcance por permisos, resultados almacenados, formularios de llamadas, grabaciones, flujo telefónico, generación del PDF ni filtros automáticos.

## Versiones y publicación

La hoja de Hostinger se comprobó con el hash `5547fa74370a4083da699d25f19f8d8324eb4538f6ed3f2722bfdb99f6e6e1d9`. La vista parte de la instalación de filtros automáticos `5d867653`. El publicador sólo reemplaza estos dos archivos; valida como dependencias el controlador y JavaScript automáticos, sin sustituirlos.

La comprobación no escribe. La instalación valida todas las versiones antes de escribir, conserva LF/CRLF, crea respaldo privado y permite restauración exacta si no hubo ediciones posteriores. No ejecuta SQL.

## Validación

`tests/reportes_ventas_diseno_qa.py` usa controlador y vistas reales con datos sintéticos, Manrope e iconos del sistema. Compara antes/después todos los indicadores, filas, resultados y enlace PDF en siete cortes: equipo, asesor, rango, segunda página, vacío, sin personas y personal; revisa escritorio, tablet y móvil. Se mantienen las pruebas de filtros automáticos y navegación dentro del iframe sin recargar el host del teléfono.

`tests/publicacion_reportes_asesores_diseno_test.py` comprueba versiones, rechazo de variantes desconocidas, check sin escrituras, respaldo, aplicación repetible y restauración. Comprueba que las hojas de Seguimiento/Aliados/Desempeño y los archivos de telefonía permanezcan intactos.
