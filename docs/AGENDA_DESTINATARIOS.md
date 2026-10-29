# Destinatarios y visibilidad de comentarios

El editor incluye «Destinatario», con la opción predeterminada «Todos los usuarios con Agenda» y las cuentas activas cuyo rol activo tiene el permiso activo `reuniones.ver`. La lista muestra nombre y rol; el servidor valida de nuevo la cuenta al guardar. Elegir una persona no concede permisos.

- **Todos:** mantiene el comportamiento de los comentarios compartidos anteriores: avisos para los perfiles con Agenda.
- **Una persona:** sólo el autor y su destinatario pueden ver el comentario. Únicamente el destinatario recibe la novedad en campana/toast y el recordatorio programado. El autor conserva la consulta, el borrado y el aviso de lectura del destinatario.
- **Privado:** sólo su autor lo ve y recibe sus avisos. El selector de destinatario queda desactivado. El servidor rechaza una combinación de privado y destinatario ajeno.

Se mantienen el autor real de sesión, la eliminación exclusiva del autor, las confirmaciones de lectura para el autor, la hora opcional, la consulta cada 60 segundos, la cadencia de recordatorios y los toasts de 2600 ms. La confirmación de una acción propia sigue apareciendo como antes. No cambia el alcance ni la confirmación de las reuniones.

La visibilidad se filtra en SQL antes del límite mensual y de la campana, sin excepciones para perfiles administradores. Obtener y marcar leído también comprueban autor/destinatario. Los endpoints de lectura, borrado y avisos devuelven 404 para un tercero que conozca el ID. Las lecturas históricas de terceros ya no generan recibos al autor en comentarios dirigidos. Los dirigidos existentes adoptan esta restricción sin modificar sus datos.

Si el destinatario desaparece de la lista mientras se escribe, se conserva la elección y se pide seleccionar otra cuenta o Todos. Nunca se transforma una elección dirigida inválida en un aviso general. Una respuesta incierta conserva el borrador y el destinatario y no repite el POST.

## Instalación

1. `tools/publicar_destinatarios_agenda_hostinger.sh --check`: valida los fragmentos de cinco archivos y los dos archivos nuevos, sin escribir ni consultar SQL.
2. `--apply`: instala únicamente esos cambios y crea un respaldo privado; no migra la BD.
3. `php tools/configurar_destinatarios_agenda.php --apply` y `--check`: añade la columna nullable `agenda_comentarios.destinatario_id` y su índice mediante una migración explícita e idempotente. Los registros anteriores quedan con NULL; conservan sus avisos generales y la privacidad que ya tenían. No se alteran otras tablas ni permisos.

Antes de completar la migración, los comentarios generales y privados siguen funcionando. Las creaciones dirigidas se rechazan con 503 en lugar de guardarse para Todos.

El instalador v2 admite los JavaScript anteriores y posteriores a la recuperación de conexión y la primera versión de destinatarios. Si ésta ya está instalada, cambia sólo el repositorio, la vista y el JavaScript; no requiere otra columna. Conserva las personalizaciones fuera de los fragmentos comprobados y rechaza una versión desconocida antes de escribir. La restauración consulta la BD y se detiene si hay comentarios dirigidos activos: una versión anterior podría volver a mostrar esos comentarios a terceros. No elimina comentarios ni columnas para permitir una restauración.

## Validación aislada

- `tests/agenda_comentarios_destinatarios_test.php`: autorización, casos inválidos, Todos, privado, avisos, recordatorios, autor y límites; terceros/admin excluidos por SQL, IDs directos, escrituras de lectura y recibos históricos.
- `tests/agenda_comentarios_mysql_test.py --destinatarios`: migración CLI idempotente y SQL real sobre una MariaDB efímera, además de las regresiones de privacidad y lectura.
- `tests/agenda_comentarios_destinatarios_browser.cjs`: selector, nombres como texto, escritorio/móvil, privacidad, cambios de permisos y borrador conservado.
- `tests/agenda_destinatarios_publicador_test.py`: versiones con/sin recuperación de red, LF/CRLF, personalizaciones, idempotencia, rechazo sin escrituras, respaldo/restauración y bloqueo de rollback inseguro.
- Se conservan las pruebas de hora nativa, privacidad, conexión y HTTP de Agenda y campana, incluidas las tareas de Aliados de Hostinger.
- `tests/agenda_comentarios_http_test.py --hostinger --destinatarios`: endpoints reales con sesiones, CSRF, visibilidad de autor/destinatario y 404 para terceros/admin; campana y tareas existentes intactas.

No se crean comentarios, reuniones, notificaciones o envíos reales durante estas pruebas.
