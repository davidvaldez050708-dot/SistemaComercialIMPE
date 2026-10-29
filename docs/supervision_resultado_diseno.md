# Consulta de resultados de Supervisión

Se sustituye la lista plana del diálogo por el encabezado, cuerpo desplazable y pie de los formularios del sistema. El contenido muestra un resumen del teléfono y tipo, datos de atención, resultado, observaciones originales y, para llamadas de asesores, las observaciones adicionales con autor y fecha.

La consulta conserva los resultados completos, incluidos los dobles y las etiquetas anteriores. Las fechas ya recibidas en hora de México se formatean para lectura sin convertirlas otra vez. Todos los valores se insertan como texto. El formulario de captura del asesor permanece independiente.

El diálogo nativo mantiene Escape y devuelve el foco al botón de consulta. Puede cerrarse durante la carga; una respuesta tardía no lo vuelve a abrir ni sustituye los datos de otra llamada. Los avisos de carga, falta de permiso y errores se muestran dentro del diálogo. No se incorporan controles para editar el resultado.

## Alcance de instalación

Sólo se publican:

- `app/views/telefonia/supervision_llamadas.php`
- `public/css/supervision_llamadas.css`
- `public/javascript/supervision_llamadas.js`

Los originales del paquete corresponden exactamente a los tres hashes proporcionados desde Hostinger. La personalización que condiciona el botón de resultado al registro guardado se conserva. No cambia ningún controlador, repositorio, permiso, dato SQL, servicio de grabación ni motor telefónico. Los bloques JavaScript de audio y filtros automáticos permanecen idénticos.

## Validación

- `tests/supervision_resultado_diseno_qa.py`: renderiza la vista PHP real con datos sintéticos y los estilos del sistema; comprueba Chrome en 1366×900, 390×844, 320×568 y 844×390.
- `tests/supervision_resultado_diseno_browser.cjs`: verifica resultados dobles, etiquetas históricas, notas, ausencia de notas de ventas en analistas, valores vacíos/largos, texto con HTML, errores, cierre con ambos botones/Escape, foco, respuestas tardías, audio y filtros.
- `tests/publicacion_supervision_resultado_diseno_test.py`: comprueba la instalación selectiva con LF y CRLF, rechazo de variantes desconocidas antes de escribir, respaldo, idempotencia y restauración exacta. Vigila archivos ajenos y un audio de prueba.
- Validación de sintaxis PHP y JavaScript; inspección visual de capturas de escritorio, móvil y horizontal.

Las pruebas no acceden a producción ni realizan llamadas reales. La instalación comprueba todas las versiones antes de escribir y guarda los originales fuera de `public_html`. Admite `--check`, `--apply` y `--restore RUTA_DEL_RESPALDO`.
