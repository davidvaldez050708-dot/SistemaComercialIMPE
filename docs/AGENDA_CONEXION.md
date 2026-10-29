# Recuperación de conexión en Agenda

El navegador mostraba la excepción nativa `Failed to fetch` cuando no recibía una respuesta. La carga de comentarios también dejaba visible su error después de recuperarse y utilizaba una cabecera AJAX que el router no reconocía al vencer la sesión. Una respuesta incompleta podía sustituir la lista por un arreglo vacío.

La corrección afecta únicamente `agenda_comentarios.js` y el manejo de errores de formularios en `agenda_reunion.js`:

- Las consultas de comentarios usan la cabecera `X-Requested-With: fetch`, validan el JSON y la estructura de cada comentario y tienen un límite de 15 segundos por intento.
- Una lectura con un fallo transitorio admite hasta tres intentos, con esperas de 800 y 1600 ms. No se reintentan inmediatamente los errores de acceso ni una respuesta 429 con `Retry-After`.
- Ante fallos temporales se conservan los últimos comentarios cargados. El aviso desaparece tras una carga correcta. Si se pierde el acceso (401/403), se retiran los comentarios del navegador, incluidos los privados.
- Sin conexión se evita la petición; el evento `online` vuelve a cargar. Se mantienen la consulta periódica de 60 segundos y la actualización al volver a la pestaña.
- Guardar, eliminar, marcar leído y confirmar reuniones no se reenvían automáticamente. Un fallo de comunicación muestra una indicación en español para comprobar el estado antes de repetir la operación. El borrador del comentario y su privacidad se conservan. Las peticiones de escritura de comentarios tienen un límite de 25 segundos.

No modifica SQL, permisos, destinatarios, privacidad del servidor, reuniones, horarios, calendario de referencia ni tiempos de los toasts. La corrección recupera las consultas y evita mensajes técnicos o errores persistentes después de recuperarse; no elimina posibles interrupciones de Internet o del alojamiento.

## Instalación

`tools/publicar_agenda_conexion_hostinger.sh --check` comprueba los fragmentos de ambos JavaScript antes de escribir. `--apply` conserva el resto de cada archivo y genera un respaldo con su comando de restauración. Admite las variantes de Agenda anteriores y posteriores a la protección JSON. No ejecuta SQL ni envía mensajes.

El publicador se genera con `tools/generar_publicador_agenda_conexion.py`. Debe volver a generarse si cambia cualquiera de los fragmentos incluidos.

## Comprobaciones

- `tests/agenda_comentarios_conexion_browser.cjs`: cortes de red, respuestas temporales e incompletas, pérdida y recuperación de conexión, sesión/permisos, límite del servidor, timeout y escritura única con borrador privado.
- `tests/agenda_respuesta_json_browser.cjs`: respuestas vacías, HTML, JSON inválido, error controlado y corte al confirmar; conserva el formulario y no repite el POST.
- `tests/agenda_comentarios_browser.cjs`: hora opcional, privacidad, escritorio/móvil y toasts de 2600 ms.
- `tests/agenda_conexion_publicador_test.py`: variantes históricas, LF/CRLF, conservación de personalizaciones, respaldo, restauración exacta, idempotencia y rechazo sin escrituras.

Las pruebas del navegador interceptan las peticiones y no acceden a datos ni generan comentarios o reuniones reales.
