# Analista: resultado de la marcación desde el auricular

Este corte añade el registro de las llamadas salientes libres (`DIALER` / `TELEPHONY_MANUAL`). Reutiliza `resultado_analista_form.php`, el buscador de seguimientos y la transacción existente. Las llamadas desde un seguimiento conservan su formulario y su ruta.

Al finalizar una marcación libre, la capa persistente abre inmediatamente la ventana de registro y oculta el resumen de llamada finalizada. Consulta la referencia posterior a la marca de agua tomada antes de marcar. Si la confirmación final de la PBX tarda, mantiene un aviso de preparación y reintenta automáticamente, sin exigir pulsar un botón. Al confirmarse, muestra el formulario compartido para vincular un expediente, crear una institución en un territorio autorizado o conservar la llamada sin identificar. No se busca otra llamada sólo por coincidir el teléfono. Una llamada entrante activa conserva prioridad; el registro pendiente se retoma cuando termina.

El servidor obtiene extensión, destino, fechas y segundos de los eventos persistidos. Revalida la extensión activa, sus permisos y la referencia; no acepta esas propiedades desde el formulario. Reutiliza el registro único y la transacción de entrantes sin crear una tabla nueva. La clasificación de una llamada no implica por sí sola contacto humano ni verificación de datos.

Se incluyen las protecciones del PR #202 para impedir salir de un seguimiento con una llamada pendiente. El auricular también consulta ese pendiente antes de iniciar otra marcación. Las salientes libres mantienen su obligación en la capa persistente y no permiten cerrar el formulario sin guardar.

La única corrección del reloj en el host impide, para Analista, adoptar referencias salientes ya terminadas o cambiar de referencia durante una llamada. `startCall`, `ensureWidget`, `endOutgoingWebrtc`, el observador entrante, recepción y la transacción de expediente permanecen iguales byte por byte frente a cada versión de partida. No se modifica el SDK, la negociación SIP, el audio ni las transferencias.

## Validación

- MariaDB privada local, puerto 33329: 158 comprobaciones de atención, transferencias y nuevos casos de salientes. La suite crea una base desechable y la elimina al terminar.
- Playwright: shell y vista PHP reales, confirmación PBX tardía y apertura automática sin clic, resumen oculto, formulario compartido, búsqueda de seguimiento, alta territorial, notas, error de guardado, cierre/navegación bloqueados, referencia ajena y dos llamadas consecutivas al mismo número.
- Regla pura de eventos y reloj: propiedad, vigencia, fin firmado, referencias viejas y llamadas negativas.
- Publicación con Python 3.11 y 3.6.8: variantes exactas de Hostinger, dependencias, preflight sin escrituras, permisos 644 de JS, idempotencia y reversión.
- Regresiones de verificación vigente, registro obligatorio y horario de contacto.

## Publicación selectiva

`tools/publicar_analista_salientes.py` y `tools/analista_salientes_release.json` verifican diez archivos y tres dependencias sin ejecutar el CRM ni SQL. El paquete admite los hashes proporcionados para Hostinger y conserva las variantes del controlador, reproducción de audios, conexión y micrófonos. Rechaza versiones desconocidas antes de modificar el sitio y genera respaldo privado.

Usar `--check` y luego `--apply` con el mismo sitio y directorio de respaldos, sin llamadas activas. Recargar el CRM tras terminar. Para revertir, usar `--restore RUTA_DEL_RESPALDO` con ese mismo publicador, sitio y raíz de respaldos; se bloquea si los archivos cambiaron después de instalar.

No se ha ejecutado SQL ni desplegado código en producción. Los registros de llamadas reales sólo se guardarán cuando el usuario confirme el formulario habitual.
