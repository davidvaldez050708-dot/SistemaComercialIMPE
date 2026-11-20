# Logo de Fundación en formularios

Los encabezados de Registro, Inscripción, Inscripción completa y Ejecutivas previa
usan la imagen transparente adjunta por el usuario, sin el logo de Universidad MPE.
Se conserva el archivo PNG original, sin convertirlo ni redibujarlo. La nueva ruta
evita reutilizar una imagen anterior guardada en la caché del navegador.

Las ligas existentes usan las mismas vistas compartidas; no se recrean formularios
ni se modifican tokens, registros, programas o configuraciones de aliados.
Las otras marcas del encabezado se conservan. Las pantallas de agradecimiento no
forman parte de este cambio de encabezado.

`tools/publicar_formularios_logo.py` admite las versiones canónicas y los cuatro
hashes de producción proporcionados por el usuario. Lee los archivos instalados y
sustituye exclusivamente la ruta anterior y el texto alternativo, conservando
íntegramente el resto de cada vista, incluso espacios y finales de línea.
Antes de escribir valida las cuatro vistas, el PNG y la sintaxis PHP.

Admite `--check`, `--apply` y `--restore RESPALDO`, junto con `--site` y
`--backup-root` fuera del sitio público. La aplicación hace respaldo privado,
publica primero el PNG, luego las vistas y revierte los cambios si falla. Repetir
la aplicación no crea otra copia ni duplica cambios. La restauración rechaza
archivos editados después de instalar. No consulta ni escribe la base de datos.

Validación: navegador con las cuatro vistas PHP reales a 1440 y 390 px, tamaño y
transparencia del PNG, texto alternativo, SEP conservada y ausencia de desbordamiento.
Pruebas de publicación: conservación de personalizaciones, idempotencia, respaldo,
restauración, rechazo de versiones desconocidas y reversión por fallo parcial.
