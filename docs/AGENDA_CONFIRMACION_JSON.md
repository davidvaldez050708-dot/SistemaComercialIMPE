# Respuestas de confirmación de reuniones

La captura `Unexpected end of JSON input` indica que el cliente intentó
interpretar una respuesta vacía. No demuestra que otra cuenta confirmase la
reunión ni que la operación no se haya guardado parcialmente.

Se limita el cambio a `AgendaReunionController.php` y al procesamiento de la
respuesta de acciones en `agenda_reunion.js`:

- El controlador inicializa reprogramación y expediente sólo en las acciones
  que los usan. Una confirmación normal no abre las conexiones de esos módulos.
- Las excepciones al iniciar una petición fetch, ejecutar una acción o
  codificar su respuesta devuelven JSON con HTTP 500 y referencia, nunca éxito.
- Se conserva el resultado de los servicios, los estados HTTP 403/405/409/422,
  los permisos, la asignación territorial y todas las reglas de las reuniones.
- El cliente reconoce respuestas vacías, HTML o JSON inválido sin mostrar el
  mensaje técnico de `Response.json`. Conserva el formulario y no repite el POST
  automáticamente. No comunica éxito sin recibir `ok` del servidor.
- Las respuestas válidas conservan su toast y navegación existentes. No se
  cambian las duraciones ni el servicio de notificaciones.

No se cambian SQL operativo, servicios de negocio, datos, plantillas, correos,
privacidad de comentarios, campos del formulario o confirmación de reuniones.

Los errores se registran en `storage/logs/agenda_reunion_json.log`, si la carpeta
es escribible, o en el registro PHP como respaldo. Sólo contienen referencia,
acción, clase, código, archivo y línea. No contienen enlaces, notas, datos del
formulario, credenciales o mensajes SQL.

## Diagnóstico de sólo lectura

`php tools/diagnosticar_confirmacion_reunion.php --check` usa el repositorio
instalado: ejecuta las consultas de reunión y cruces, prepara la confirmación y
la interacción, y comprueba los valores SISTEMA/OTRO del esquema. El adaptador
sólo ejecuta SELECT; no ejecuta las sentencias INSERT/UPDATE preparadas. No
confirma ni crea reuniones, interacciones o mensajes. Usa una reunión existente
internamente, sin imprimir sus datos.

Las comprobaciones iniciales aportadas de Hostinger muestran inicio del
controlador y columnas requeridas correctos, sin excepción localizada en los
registros consultados. Eso no identifica todavía la excepción concreta del
intento fallido en producción. Se necesita el diagnóstico del repositorio o la
referencia de una respuesta del servidor para atribuir una causa adicional;
las pruebas locales no constituyen una confirmación real en Hostinger.

## Publicación

`tools/publicar_confirmacion_json_hostinger.sh --check|--apply|--restore RUTA`
aplica fragmentos sobre los dos archivos existentes y añade el diagnóstico CLI.
No ejecuta SQL ni envíos. Conserva cambios ajenos, LF/CRLF, respaldo privado,
idempotencia y restauración; rechaza versiones incompatibles antes de escribir.
Se genera con `python tools/generar_publicador_confirmacion_json.py`.

## Validación

- `tests/agenda_respuesta_json_http_test.py`: HTTP del controlador, excepciones
  de inicio/acción/codificación, permisos, métodos, conflictos y metadatos sin
  datos privados.
- `tests/agenda_respuesta_json_browser.cjs`: siete respuestas vacías/HTML/JSON
  inválido o errores de API; conserva formulario y no duplica peticiones.
- `tests/agenda_confirmacion_json_mysql_test.py`: controlador y servicios reales
  contra MariaDB efímero; confirmar, confirmar reprogramación e historial,
  conflicto al repetir y excepción SQL controlada. El diagnóstico se ejecuta
  antes y se comprueba que no escribió nada.
- `tests/agenda_confirmacion_diagnostico_test.py`: repositorio real con conexión
  sintética que rechaza cualquier ejecución de escritura.
- `tests/agenda_json_publicador_test.py`: instalación LF/CRLF, rechazo seguro,
  idempotencia y respaldo/restauración, sin cambiar servicios o notificaciones.
- Regresiones de confirmación/campana y toasts de reuniones. Se normaliza LF/CRLF
  al comparar código histórico en la prueba de campana, sin modificar esa lógica.

Todas estas pruebas usan datos sintéticos, sin modificaciones de producción.
