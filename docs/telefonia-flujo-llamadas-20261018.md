# Correcciones del flujo de llamadas del 18 de octubre de 2026

Esta es la primera entrega del día. Para la revisión posterior de consultas, entrega explícita y campanas, consultar [Operación de transferencias](telefonia_transferencias_operacion_2026_10_18.md).

La prueba real del usuario confirmó entrada, salida, transferencia de entrante, colgado, navegación entre módulos y llamadas consecutivas. Esta entrega atiende los problemas observados al transferir una saliente, registrar resultados y recibir llamadas perdidas.

## Cambios

- El origen se muestra como «Transferida desde: Extensión 100» cuando la centralita identifica esa extensión. Antes se mostraba siempre la extensión local como «Desde». Si todavía no se conoce el origen, se indica «Recibida en», sin inventar quién transfirió.
- Un identificador provisional del navegador no se usa para buscar la llamada definitiva en la centralita. Se conserva la consulta por identificador exacto una vez confirmado.
- Una transferencia de saliente se reconoce también cuando la centralita envía respuesta del analista con iniciador y saliente original en el mismo grupo, aunque no haya enviado el aviso de llegada. Se conserva el teléfono externo y sólo se cuenta el tramo atendido por el analista. Sin evidencia suficiente, el audio continúa pendiente.
- En la bandeja del analista desaparecen los términos técnicos y el botón Registrar resultado en llamadas no contestadas. El contador Pendientes incluye conversaciones atendidas pendientes de resultado.
- Cada llamada comienza con el panel expandido y centrado. Se mantiene la posibilidad de moverlo o minimizarlo durante esa llamada.
- Se distingue Marcando de Timbrando. Un tono local respalda el timbrado cuando no hay audio remoto y se detiene al contestar, colgar o detectar una locución o tono de la operadora. El permiso de reproducción depende del navegador; la interacción de marcado intenta habilitarlo.
- Recepción registra obligatoriamente el resultado de una conversación terminada antes de marcar otra. Conserva captura ante errores y recargas; una nueva entrante tiene prioridad y después se retoma el pendiente. El resultado sigue siendo definitivo al guardar.
- Las perdidas se cierran automáticamente sin pedir un resultado que el operador no pudo observar. Las transferencias conservan su destino automáticamente. La pantalla conserva un aviso de perdidas hasta revisar el historial; el reconocimiento del aviso se guarda por usuario y extensión en ese navegador.
- Consultar primero queda señalado como recomendado. El timbrado de una consulta no cierra el panel de origen mientras la sesión de teléfono sigue viva. Si la centralita informa una devolución, deja de mostrarse como entrega completada.

## Centralita y transferencias sin respuesta

Zadarma documenta la transferencia con consulta mediante `*extensión#` y la devolución a la extensión inicial tras 25 segundos sin respuesta. El CRM ya dispone de ese tipo de transferencia y esta entrega conserva su sesión durante la consulta. El regreso efectivo y su tiempo deben comprobarse con la centralita real. No se ha modificado la cuenta de Zadarma, sus desvíos ni sus escenarios.

Fuente: https://zadarma.com/es/support/instructions/pbx/

## Pruebas de aceptación en producción

1. Marketing llama al celular y transfiere a 101: verificar origen 100, audio en ambos sentidos, duración propia y grabación cuando la centralita la entregue. Clasificar también una conversación sin identificar institución.
2. Repetir con una entrante transferida para comprobar que conserva el comportamiento que ya funcionaba.
3. Consultar primero a 101 y no contestar. Mantener a recepción conectada; comprobar el regreso y el aviso al analista. Si la centralita corta también en consulta, revisar su configuración antes de usarla como mecanismo de retorno.
4. No contestar una entrante a recepción: comprobar aviso y ausencia de formulario de resultado.
5. Terminar una conversación sin transferencia: debe abrirse el resultado obligatorio. Intentar Escape, un guardado con error y recargar para comprobar conservación del borrador.
6. Recibir otra llamada mientras existe ese pendiente: debe poder contestarse y después retomarse el resultado anterior.
7. Transferir correctamente: no solicitar un resultado manual adicional de recepción.
8. Marcar desde Seguimiento y desde el teléfono general: verificar Marcando, Timbrando, silencio del tono durante conversación o locución y ausencia de tonos después de colgar.

## Publicación

Publicador: `tools/publicar_flujo_llamadas_hostinger.sh`.

Primero ejecutar `--check`. Sólo después de una revisión compatible, ejecutar `--apply` con las extensiones sin llamadas activas. Publica 26 archivos con copia de seguridad, comprobación de sintaxis y restauración si falla la escritura. Rechaza diferencias incompatibles; no fuerza archivos personalizados. La revisión posterior incorpora dos tablas nuevas, preparadas por los servicios al utilizarse; no modifica registros anteriores de forma masiva. Es necesario cerrar sesión y volver a entrar para renovar el teléfono persistente.

La aplicación se valida con base desechable y navegador real. La conexión de audio, la entrega de grabaciones y el retorno de una transferencia necesitan además las pruebas reales con Zadarma.
