Cierre del modal tras rechazar en Recepción
=========================================

La prueba real mostró que el rechazo ya produce una locución en el celular,
pero el CRM conserva «Rechazando llamada». Es distinto del reintento circular
configurado en Zadarma. Esta entrega parte del paquete a1e150b5 y modifica
únicamente `telefonia_host.js` y `telefonia_entrantes_bridge.js`.

Recepción no carga el observador SDK de Analista/Ventas. El motor esperaba
el fin de la llamada PBX completa, que puede conservar un tramo externo o
una locución después de terminar la invitación de la extensión.

El adaptador conserva evidencia del rechazo exacto:

- Suscribe ended/failed antes de terminate para no perder un cierre síncrono
  ni depender de que webCallSession permanezca visible hasta el sondeo.
- Acredita también el reposo PUSH sostenido durante dos segundos, exigiendo
  callState vacío, ausencia de timbrado y de sesión viva y navegador conectado.
- La mera espera, pulsar Rechazar o faltar un webhook no acredita cierre.
- Un diálogo diferente vivo, una sesión establecida o respuesta ya confirmada
  impiden liberar esa llamada por un rechazo anterior.
- La nueva invitación descarta la evidencia del rechazo previo. Los eventos
  atrasados quedan ligados a su registro original y no cierran otra llamada.

Sólo en Recepción, cuando esa evidencia está disponible, el motor publica
estado local ready/idle y el shell existente cierra el modal. No se publica
un END PBX, no se retira permanentemente la referencia y no se escribe SQL.
Las llamadas posteriores, incluso reofertas del mismo PBX, conservan controles.
Analistas y Asesores mantienen su observador y sus formularios actuales.

Validación sin llamadas reales
-----------------------------

- `node tests/recepcion_cierre_local_sdk.cjs`
- `node tests/recepcion_cierre_local_browser.cjs` (Chrome/Playwright)
- `node tests/recepcion_rechazo_sdk.cjs`
- `node tests/recepcion_rechazo_browser.cjs`
- `node tests/telefonia_sdk_entrantes_observador_smoke.js`
- `node tests/telefonia_timbrado_antes_sip_smoke.js`
- `node tests/telefonia_entrantes_bridge_smoke.js`
- `python tests/publicacion_recepcion_cierre_local_test.py`
- `node --check` de ambos archivos.

El publicador conserva los bytes anteriores con respaldo privado, rechaza
versiones desconocidas y cambios en las dependencias antes de escribir,
respeta LF/CRLF y permite restauración exacta con `--restore RUTA`.
No modifica configuración de Zadarma, bases de datos ni grabaciones.

Después de instalar sin llamadas activas, cerrar y reabrir las pestañas del
CRM para cargar los dos archivos de la misma entrega. La prueba final real
pendiente es rechazar una llamada a Recepción antes de responder, comprobar
el cierre del modal al quedar libre el teléfono, llamar de nuevo y comprobar
que se puede contestar y colgar normalmente. El comportamiento de la locución
del operador/PBX en el celular no se cambia con este ajuste local.
