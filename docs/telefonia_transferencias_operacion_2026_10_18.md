# Consulta y entrega de llamadas

Esta revisión se conserva como antecedente. El recorrido vigente usa [confirmación de disponibilidad por CRM y transferencia directa](telefonia-disponibilidad-20261018.md).

En **Consultar primero**, la persona espera mientras recepción habla con el analista. Contestar esa consulta todavía no entrega a la persona. Recepción debe pulsar **Completar transferencia** después de hablar con el analista. Se libera únicamente su sesión del teléfono; no se utiliza la API que cuelga la llamada externa completa.

En **Transferir directamente**, recepción sale cuando la centralita confirma el envío al destino. No puede recuperar a voluntad una llamada ya entregada. El desvío por falta de respuesta de una transferencia directa depende de la configuración de la centralita; esta entrega no modifica esa configuración.

Si termina una consulta sin entrega, recepción conserva su llamada y ve el aviso para retomar la conversación. El analista conserva una tarjeta «Transferencia sin respuesta», sin grabación ni registro de resultado, y recibe un aviso persistente en su campana si no contestó; puede abrir su historial para coordinar la devolución con recepción.

## Cambios

- Operación persistida por llamada, con participantes, intentos y etapas separadas. Cerrar la ficha no permite enviar otra transferencia. Sólo una devolución o un fallo permiten reintentar.
- La consulta interna no se ofrece como otra llamada institucional. El tramo externo utiliza el mismo identificador confirmado de la centralita y empieza al entregar; no duplica el registro observado por el teléfono.
- Los avisos de llamadas perdidas aparecen en las campanas existentes de Marketing y del analista. Su lectura pertenece al usuario y extensión actuales; no reaparecen al sincronizar.
- La coincidencia telefónica puede descartarse con «No corresponde a estas instituciones». Se elige el estado y responsable sin atribuir un expediente por el número compartido.
- Texto compacto, estado de consulta y botón para completar en el mismo estilo del teléfono.
- El tono local no termina por el mero establecimiento del puente SIP. Cede ante audio remoto, tono nativo o conversación confirmada. Se fija al máximo el volumen del elemento de timbrado entrante del SDK; el volumen físico del equipo sigue dependiendo de sus ajustes.
- El resumen atendido por el analista también funciona cuando el identificador de la centralita llega antes que la observación local del teléfono.

Las dos tablas nuevas se crean de forma aditiva al utilizar los servicios. La migración SQL equivalente queda en `database/migrations/2026_10_18_transferencia_operaciones_notificaciones.sql`. No se borran ni se reclasifican registros históricos de forma masiva.

## Pruebas de aceptación después de publicar

Usar recepción 100, analista 101 y un celular externo, con ambos CRM recargados y sin llamadas anteriores abiertas.

1. Llamar desde el celular a recepción. Consultar primero a 101. Al contestar, sólo recepción y analista deben conversar; el celular permanece en espera. Debe aparecer **Completar transferencia** en recepción.
2. Completar la transferencia. El celular y 101 deben escucharse en ambos sentidos y debe cesar el tono de espera. Recepción queda libre. Colgar desde el celular y repetir colgando desde el analista. La atención externa debe pedir resultado al analista y quedar una sola vez en su historial. Marketing debe mostrarla transferida a 101, sin resultado manual obligatorio.
3. Repetir desde una llamada **saliente** hecha por recepción al celular. Debe conservarse el número externo, el destino 101 y la grabación cuando la centralita la confirme.
4. Consultar a 101 y no contestar. Al devolver la centralita la conversación, recepción debe ver el aviso para retomarla y hablar de nuevo con el celular. El analista recibe una notificación en la campana, sin formulario de resultado para una conversación inexistente. Abrir el aviso, recargar y comprobar que ya no esté pendiente.
5. Contestar la consulta y rechazarla verbalmente, colgando el analista antes de completar. Recepción debe conservar a la persona y poder elegir otro destino. No contar la consulta como verificación institucional.
6. Transferir directamente. Verificar que recepción quede libre y 101 reciba al celular. Probar también sin respuesta: el retorno/desvío depende de las reglas activas en la centralita y debe revisarse allí si no coincide con la operación deseada.
7. Abrir la ficha de transferencia durante una consulta, cerrarla y volver al teléfono. No debe permitir otra transferencia mientras la primera siga en curso. Completar o esperar devolución antes de reintentar.
8. Con un número que coincida con un expediente, elegir **No corresponde a estas instituciones** y seleccionar estado/responsable. No debe enlazar el expediente sugerido.
9. Hacer llamadas salientes desde recepción y desde Seguimiento. Comprobar Marcando, Timbrando y conversación; escuchar varios ciclos del tono, y verificar que cese al contestar o colgar. Probar un número ocupado/no disponible sin inventar una conversación efectiva.
10. Dejar una entrante de recepción sin respuesta. Revisar su campana. Terminar una conversación atendida sin transferir: sigue siendo obligatorio guardar un resultado definitivo.

Registrar para cualquier fallo: hora, origen/destino, entrante/saliente, modo de transferencia, quién colgó y qué escucharon las tres personas. No basta con observar el cronómetro: comprobar siempre audio en ambos sentidos.

## Validación automatizada

Base MySQL aislada, reglas de identidad/permisos, etapas de transferencia y avisos; motor JavaScript con sesiones SIP simuladas, pérdida de red y reemplazo de diálogo; navegador real para modales, descarte de coincidencias, bloqueo de nuevas transferencias y lectura de las dos campanas. No sustituye una llamada real con la configuración de Zadarma de producción.
