# Disponibilidad antes de transferir

Este recorrido sustituye la consulta telefónica de la entrega anterior. No se utiliza una llamada interna para preguntar si el responsable puede atender.

1. Recepción conserva la conversación con la persona, identifica el destino y pulsa **Solicitar disponibilidad**.
2. El responsable recibe un modal del CRM con **Puedo atender** o **No disponible**. La solicitud vence a los 45 segundos. El cliente continúa hablando con recepción, sin música de espera por esta solicitud.
3. Si acepta, recepción dispone de 30 segundos para pulsar **Transferir llamada**. Sólo entonces se emite la transferencia directa existente. Aceptar no genera por sí mismo tonos ni una transferencia.
4. Si rechaza o no responde, recepción puede elegir otro responsable o registrar nombre, teléfono y motivo en **Solicitar devolución**. El responsable recibe un aviso en la campana y encuentra el pendiente en **Llamadas por devolver**.

Cerrar y abrir la ficha no permite duplicar solicitudes. La aceptación pertenece a una llamada, un destino y un intento concretos; no se puede utilizar después de vencer ni para otro número. Se revalida el directorio autorizado antes de enviar la transferencia. Un teléfono ocupado, desconectado o con resultado pendiente no puede aceptar en el recorrido normal. Si se ocupa después de aceptar, se revoca la disponibilidad antes de emitir la orden.

## Datos y resultados

Las tablas `telefonia_presencia` y `telefonia_disponibilidad` se preparan de forma aditiva por el servicio; existe la migración SQL equivalente. No se modifican los audios ni se eliminan llamadas anteriores.

Registrar una devolución y marcarla atendida no acreditan una llamada efectiva ni verifican datos institucionales. El resultado de la conversación de recepción sigue siendo obligatorio al terminar sin transferencia; el analista registra el resultado de la atención real que reciba.

La confirmación tiene un plazo breve porque la situación puede cambiar. La atención efectiva de una transferencia directa sigue dependiendo de que el teléfono responda; una aceptación no simula una respuesta SIP ni modifica los desvíos de Zadarma.

## Validación

Pruebas MySQL con usuarios y extensiones aisladas: pertenencia, rechazo de autoaceptación, destino ocupado, aceptación, caducidad, uso único, intento antiguo, revocación por nueva llamada y devolución con aviso y cierre propios.

Navegador real con recepción y analista: ninguna orden TRANSFER al solicitar o aceptar; botón visible sólo tras aceptación; una orden explícita al transferir; rechazo y vencimiento sin llamada interna; devolución en pantalla móvil; bloqueo al cerrar la ficha. Capturas revisadas para confirmar la escala visual de los avisos.

## Pruebas reales después de publicar

- Cerrar sesión y entrar de nuevo en ambas cuentas, con las extensiones libres.
- Entrante celular → 100: solicitar a 101, aceptar y después transferir. Durante la confirmación el celular debe seguir conversando con recepción. Tras transferir, comprobar audio celular ↔ 101 y liberación de 100.
- Repetir desde una saliente de 100 y colgar desde cada extremo. Revisar una sola atención externa y sus resultados.
- Rechazar y dejar vencer una solicitud. Debe continuar la conversación con 100; registrar devolución y revisar contacto, teléfono y motivo en la campana y en el listado del responsable.
- Aceptar sin transferir y esperar 30 segundos. No debe permitir utilizar la aceptación vencida.
- Recibir otra llamada en 101 después de aceptar. Recepción no debe poder utilizar esa disponibilidad.
- Cerrar y abrir la ficha mientras espera respuesta: no debe enviar otra solicitud.

No se ejecutan llamadas reales ni se cambia la cuenta de Zadarma durante la validación automatizada.
