Corrección de rechazo entrante antes de contestar
================================================

Se parte de las versiones de Hostinger identificadas por el usuario, incluyendo
la variante completa del motor reconstruida y verificada con su SHA-256 exacto:

| Archivo JS | SHA-256 instalado |
| --- | --- |
| telefonia_shell.js | cc6edd9019fe9c7248ac98f5927cecc318a006007543e95ca28012294e0a59ed |
| telefonia_host.js | 2dba00b020a1b8db9e6064838fa2610c370d1380b2b0a04f869a0b319852c6b7 |
| telefonia_entrantes_bridge.js | 76c5ba64a410e12a16284467c4694a33d588ad2189217af2d032e0fbbd1534b4 |
| telefonia_sdk_entrantes_observador.js (CRLF) | 2a73decdc8472cfd42296feac73bce39f0dcdcb9a112ed561865bea28057a32a |

El último tiene SHA normalizado LF
`2c836929d6abf8fce0ce9667580e7b3317d29e583eea46e372882b836686d1ee`.
El manifiesto conserva ambos contenidos anteriores normalizados. El publicador
respeta los saltos de línea y respalda los bytes anteriores originales.

Causa y alcance
---------------

En la recepción PUSH anterior a RTCSession, el adaptador invocaba `cancel()`.
El botón de rechazo del widget oficial invoca `finishCall()`, que establece el
estado `canceling` antes de registrar el rechazo SIP. Se adopta ese mismo
control completo, conservando la terminación SIP 486 idempotente cuando ya
existe una sesión entrante sin responder. No se termina una sesión saliente
ni una conversación establecida desde Rechazar.

Fuentes primarias inspeccionadas, sin ejecutar llamadas:

- https://my.zadarma.com/webphoneWebRTCWidget/v8/js/widget.min.js?sub_v=68
- https://my.zadarma.com/webphoneWebRTCWidget/v8/js/widget-api.min.js?sub_v=68

El shell ocultaba el modal inmediatamente y su pending de rechazo nunca
vencía. Ahora conserva el panel con «Rechazando llamada» y habilita consultar
el estado tras la espera de 4,5 segundos, sin reenviar un colgado a otra sesión.
No presenta un rechazo pendiente como una conversación.

El observador recupera una nueva oferta incoming posterior a canceling, incluso
sin haber observado reposo entre ambas. Las clases residuales por sí solas no
reabren una llamada. El host libera la supresión anterior exclusivamente ante
una nueva invitación SDK; un ringing PBX atrasado sigue sin reabrirla.
Recepción, que no carga el observador de Analista/Ventas, tiene la misma
recuperación en el motor: exige transición nativa hacia incoming y controles
de timbrado visibles. La nueva invitación libera la deduplicación del rechazo
anterior aun cuando la centralita conserve el mismo PBX ID.
También se reconoce el reposo sostenido después de rechazar un PUSH sin sesión.

No hay SQL, cambios de rutas de centralita, resultados, grabaciones, permisos,
configuración de extensiones ni cambios a los formularios comerciales.
Las personalizaciones de transferencias y recuperación de reserva se conservan.

Validación
----------

- `node tests/recepcion_rechazo_sdk.cjs`
- `node tests/recepcion_rechazo_browser.cjs` (Playwright y Chrome)
- `node tests/telefonia_timbrado_antes_sip_smoke.js`
- `node tests/telefonia_sdk_entrantes_observador_smoke.js`
- `node tests/telefonia_entrantes_bridge_smoke.js`
- `python tests/publicacion_rechazo_recepcion_test.py`
- `node --check` de los cuatro archivos modificados.

El navegador de prueba usa red interceptada y SDK simulado. La prueba final
con telefonía real debe realizarse después de instalar: llamar a Recepción,
rechazar antes de contestar, comprobar el fin en el celular y repetir la
llamada para verificar que aparecen los controles. El cierre local SIP no
garantiza por sí solo que una cola PBX haya terminado el tramo del llamante;
si la centralita lo ofrece de nuevo, el CRM debe mostrar sus controles.

Instalar con todas las llamadas finalizadas y reabrir las pestañas del CRM
después para cargar el motor y el adaptador de la misma entrega. El publicador
rechaza versiones desconocidas antes de escribir y admite `--restore RUTA`
con el respaldo privado generado por esa misma entrega.
