# Próxima acción sugerida y editable

Corrige el formulario de interacciones de Seguimiento y la recuperación del borrador obligatorio de llamada. El resultado sigue sugiriendo la próxima acción. Una selección manual, incluida «Sin próxima acción», y su fecha se conservan al actualizar el panel o recuperar el borrador. Un evento repetido del mismo resultado no borra la elección. Si se elige un resultado diferente, se calcula su nueva sugerencia.

Al restaurar, se calcula primero la sugerencia y después se recuperan la acción guardada y la fecha. Sólo se restauran opciones permitidas y visibles; las restricciones del resultado y de la ruta siguen vigentes. La validación al guardar usa la obligatoriedad de fecha del formulario, eliminando una referencia a una lista que ya no estaba definida en la versión instalada. Se conserva la programación de próximas acciones en etapas avanzadas.

Se publican únicamente `public/javascript/seguimiento_interacciones.js` y `public/javascript/seguimiento_llamada_registro_obligatorio.js`. Sus bases coinciden exactamente con los SHA-256 de producción compartidos el 19 de noviembre de 2026: `ad37d2028882f6315d0cec008306011648c3259592ad4c9b27a2437f37b51e40` y `c8046225838aed15ef3b6d7e00b3cdcf15de798b461ecbdbc9f167eace59b3e7`. No cambia estilos, servicios PHP, configuración, SQL, llamadas o registros existentes.

`tools/publicar_analista_accion_editable.py` ofrece CHECK, APPLY y `--restore RUTA_EXACTA`, usando el motor de publicación existente con un manifiesto propio fijado por SHA-256. Comprueba las dos bases, conserva originales en respaldo privado, publica de forma atómica por archivo, revierte fallos parciales y bloquea la reversión si hubo personalizaciones posteriores. CHECK no escribe ni consulta la base; repetir APPLY no crea cambios adicionales.

Pruebas:

- `node tests/seguimiento_accion_editable_browser.cjs`: carga los dos clientes reales y usa eventos de usuario en Edge/Playwright. Comprueba Ocupado → Volver a llamar, cambio a WhatsApp y fecha, tres actualizaciones/restauraciones, evento repetido del resultado, cambio real del resultado, reset del borrador, Sin próxima acción y serialización correcta al guardar tanto en ruta inicial como avanzada. No llama a servicios externos ni guarda datos reales.
- `python tests/seguimiento_accion_editable_publicacion.py`: sitio sintético con las dos bases exactas, CHECK sin escrituras, bloqueo de versiones desconocidas, respaldo, APPLY idempotente, rechazo de ediciones posteriores, reversión y fallo parcial.

Después de publicar y cargar el cliente nuevo, registrar una llamada de prueba con resultado Ocupado, cambiar la sugerencia a Enviar WhatsApp y elegir una fecha futura. Esperar una actualización del panel, guardar y comprobar que el expediente y la próxima acción muestran la selección elegida.
