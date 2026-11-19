# Pasos y municipios de la plantilla de Seguimiento

Corrige el generador de la plantilla V3 sin modificar las 19 columnas de importación ni el lector/confirmador. La base exacta auditada tiene SHA-256 `52880880eb3f5e1106593fb048c5a8292e3d0b516b0d6b0520072f8ef19b3af1`; el importador protegido conserva `2857504c1ae345b30b36082a4da9e268d86e61ce533926f177440a07cec554e5`.

El catálogo escribía A, B, D, C en las filas con estados. Se generan ahora en orden A, B, C, D. El rango PasosHistoricos conserva las catorce opciones 00–13. La prueba inspecciona todas las filas XML para evitar referencias desordenadas o duplicadas, además de leer los valores del rango y subir/importar los catorce pasos reales del archivo generado.

Por elección del usuario se entrega una sola plantilla para Excel y Google Sheets. Municipio usa una lista fija `MunicipiosDisponibles`, formada únicamente con municipios activos de los estados autorizados del usuario, sin duplicar nombres compartidos por varios estados. Se mantiene el texto original del municipio para el lector existente. La hoja Municipios se hace visible y conserva también las columnas por estado para consultar la correspondencia. La guía explica que el desplegable reúne todos los municipios autorizados y que el CRM valida cada pareja estado–municipio.

Se elimina INDIRECT de la validación y el rango vacío que apuntaba a la última fila de Excel. Google Sheets admite listas basadas en un rango A1 fijo; referencia: https://developers.google.com/workspace/sheets/api/reference/rest/v4/spreadsheets/other#conditiontype. La comprobación automatizada verifica la representación OOXML y la lectura del CRM, incluyendo cadenas compartidas, pero no acredita una conversión ejecutada en una sesión nativa de Google Sheets. Esa apertura debe verificarse con el archivo corregido.

Se conservan los cuatro campos obligatorios y los opcionales, estilos institucionales, rangos de captura de 250 filas, nombres de hojas, esquema, catálogos territoriales y alcance de permisos. Los anchos de los catálogos se ajustan para leer completas las etiquetas de pasos y municipios. No se importan registros de la cuenta real durante las pruebas. Los pasos reportados se guardan como antecedentes; no generan llamadas, verificaciones, reuniones, convenios ni avance operativo ficticio.

## Pruebas reproducibles

- `php tests/seguimiento_importador_excel_smoke.php`: plantilla y significado de todos los pasos.
- `python tests/seguimiento_excel_municipios_test.py`: OOXML ordenado, pasos 00–13, lista municipal fija, datos autorizados/activos, permisos de descarga y catálogo consultado en lote. Requiere openpyxl sólo para lectura; el archivo se genera con el servicio PHP real.
- `IMPE_TEST_MYSQL_PORT=PUERTO_AISLADO IMPE_TEST_MYSQL_PASSWORD=CLAVE_SINTETICA python tests/seguimiento_excel_plantilla_http_test.py`: genera y llena un archivo sintético con 14 pasos, carga multipart al lector real, pasa esos registros a previsualización/confirmación en MariaDB aislada y comprueba historial, datos guardados, reimportación sin duplicados, cuatro obligatorios, opcionales, municipios incorrectos/inactivos y revocación territorial con rollback. Se prohíbe el puerto 3306 y se elimina sólo el esquema de prueba creado por el test.
- `python tests/publicacion_seguimiento_excel_catalogos_test.py`: CHECK de sólo lectura, un archivo publicado, dependencia de importación intacta, bases desconocidas, respaldo, APPLY idempotente, restauración protegida y fallo al publicar.

PHP_BINARY/PHP_BIN permiten elegir el PHP CLI de prueba. La prueba HTTP sin IMPE_TEST_MYSQL_PORT sólo comprueba la lectura y no conecta a una base.

## Entrega

`tools/publicar_seguimiento_excel_catalogos.py` usa el motor de entrega existente con un manifiesto fijado por SHA-256. Sólo publica `app/services/SeguimientoExcelPlantillaService.php`. No consulta ni modifica SQL, configuración, cuentas o seguimientos. CHECK verifica el generador y la versión protegida del importador antes de escribir. APPLY conserva el original en un respaldo privado y realiza sustitución atómica. La restauración se realiza con `--restore RUTA_EXACTA` y bloquea si hubo personalizaciones posteriores.

Después de aplicar, las nuevas descargas incluyen la corrección. Los archivos descargados anteriormente no se actualizan solos. La copia corregida entregada al usuario fue regenerada con el servicio PHP y los 13 estados y 572 entradas municipales de su plantilla adjunta, sin usar datos operativos ni inventar municipios.
