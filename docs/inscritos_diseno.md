# Inscritos: diseño, paginación y recorrido de datos

El nombre visible pasa a Inscritos en encabezado y menú de escritorio/móvil. El panel muestra Todos los inscritos y elimina el bloque de icono, título y descripción que repetía la cabecera. Los filtros, tabla, botones y tipografía siguen el diseño de Prospectos, sin una segunda tarjeta alrededor de los filtros. La paginación usa números, página activa, extremos y elipsis, con controles acotados aunque haya muchas páginas. Conserva búsqueda, programa y fechas al navegar.

## Llegada de formularios

FormularioInscripcionPantalla::guardar valida la captura y llama a FormularioInscripcionRepository::guardar. Este repositorio guarda atómicamente la fila principal en formulario_inscripciones y el detalle en formulario_inscripciones_completas. InscritosModel consulta exactamente esas dos tablas mediante el identificador de inscripción. Los formularios públicos completos de Ejecutivas, Experiencia laboral y Bachillerato General y la captura interna llegan a esta bandeja. Las dos opciones aún pendientes de términos no admiten envíos. No se mezclan los registros de beca/Prospectos ni las capturas antiguas que sólo tenían fila principal sin detalle completo.

La prueba de integración usa envíos HTTP multipart sintéticos, el repositorio real y el modelo real de Inscritos, en base aislada. Confirma tres programas públicos y una captura interna visibles. No se crearon inscripciones reales ni se consultaron datos personales de producción. El usuario confirmó que nadie ha llenado los formularios; por ello el estado vacío de su captura es coherente.

## Comprobantes y exportación

Subir un comprobante conserva un archivo; no verifica por sí mismo un depósito. Este módulo consulta y descarga los archivos según permisos y no tiene una acción para acreditar pagos. La ficha dice Archivo recibido · pago sin validar y aclara que adjuntarlo no confirma que se haya acreditado. La ayuda del Excel se separa de esa explicación.

La exportación mantiene los mismos filtros del listado, excluye la página y recupera todos los resultados. Máximo 10,000 por descarga. Si se superan, se pide acotar filtros; no se recorta el archivo silenciosamente. Las pruebas verifican 10,000 filas exportables y rechazo de 10,001, XLSX real, fechas de México, caracteres de búsqueda y protección frente a fórmulas.

## Publicación

publicar_inscritos_diseno.py y inscritos_diseno_release.json actualizan únicamente controlador (textos), sidebar (dos etiquetas), vista, CSS y JS de la bandeja. Se cotejaron exactamente los siete hashes aportados por el usuario. Modelo y repositorio se verifican como dependencias y no se reemplazan. Se conservan rutas, permisos, captura, comprobantes, SQL y datos.

El instalador sellado permite --check sin escrituras, --apply con respaldo privado e idempotencia y --restore con comprobación de cambios posteriores. Compatible con Python 3.6 y posteriores. Los assets públicos quedan 644. Las pruebas del instalador original se fijan a su publicación histórica, sin cambiar ese instalador.
