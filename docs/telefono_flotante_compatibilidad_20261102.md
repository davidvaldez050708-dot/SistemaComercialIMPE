Compatibilidad del teléfono flotante
===================================

Incidente: Marketing/Recepción en Chrome 86.0.4240 abre el teclado desplazado
fuera de la esquina superior izquierda y no puede moverlo. La corrección del
marco del CRM no bastaba: el marcador tiene un contenedor fijo independiente.

Versiones públicas descargadas y verificadas antes de preparar el paquete:

| Archivo | SHA-256 |
| --- | --- |
| public/css/telefonia_teclado.css | 5b3db3c4169b35625c8f9d564f195b795015875f4addcf6a4db6b5e0f57dad33 |
| public/javascript/telefonia_teclado.js | e1639b090266b1cf5140176b94967b25b8c354de0cb00cb1566d1e16a3b80aff |
| public/css/telefonia_shell.css | 9557c422fc59e857ab494fbb7ad93dc4f0ce715aafaf31ad4f7ef7bcc1a77864 |

La última coincide con el paquete anterior de compatibilidad del marco. Se
conservan literalmente ambas hojas previas y se agregan reglas al final.

Hallazgos y alcance
------------------

- El wrapper y backdrop del marcador dependían de inset, ausente en Chrome 86.
  Al ignorarlo, la prueba reproduce x=-210 e y=-233.59 para un panel de 420 px
  en un viewport de 1366 x 768. Se agregan los cuatro bordes y tamaño completo.
- max-height dependía de dvh. Se proporcionan límites equivalentes con vh.
- El teclado no tenía arrastre. Se añade sólo al encabezado, excluyendo sus
  controles interactivos, con Pointer Events/captura y límites del viewport.
- El panel se recoloca al cambiar el tamaño, cambiar su contenido o reducirse
  visualViewport. Minimizar/restaurar conserva una posición válida. Una nueva
  apertura sin llamada recupera el centrado. No se persisten coordenadas viejas.
- Chrome 86 tampoco tiene :has(), usado en las acciones finales de Ventas.
  Un bloque alternativo mantiene los botones adicionales a ancho completo sin
  superponerlos. Los navegadores actuales conservan sus selectores anteriores.

Fuentes de compatibilidad consultadas:

- https://github.com/mdn/browser-compat-data/blob/main/css/properties/inset.json
- https://github.com/mdn/browser-compat-data/blob/main/css/at-rules/supports.json
- https://github.com/mdn/browser-compat-data/blob/main/css/selectors/has.json

Sólo cambian dos CSS y el componente visual telefonia_teclado.js. Se mantienen
las funciones normalizar, dial, sync y transferCall literalmente idénticas a
la versión instalada. No cambia el SDK, host, rechazo, resultados, permisos,
grabaciones, SQL ni la transferencia de control al panel único del shell.

Validación
----------

`node tests/telefono_flotante_compat_browser.cjs` ejecuta Chrome actual con red
bloqueada y API simulada. Modela las reglas CSS ignoradas por Chrome 86; no es
una ejecución del binario antiguo. Reproduce el fallo con el CSS anterior y
valida 12 combinaciones de perfiles CSS, rol/extensión, tamaño y modo clásico
o shell. Comprueba centrado, arrastre por los bordes, resize, cierre, permisos,
llamada única, minimizar/restaurar, silenciar/transferir/colgar en el respaldo
clásico y combinaciones de botones de resultado sin :has().

También se ejecutan telefonia_teclado_runtime_smoke.js,
recepcion_cierre_local_sdk.cjs, telefonia_teclado_smoke.php,
telefonia_teclado_estilo_smoke.php y la prueba de publicación
publicacion_telefono_flotante_compat_test.py. Esta última valida conservación
del CSS y de funciones telefónicas, integridad, LF/CRLF, check sin escrituras,
bloqueo de variantes desconocidas, idempotencia y restauración exacta.

El publicador instala únicamente las tres versiones exactas de la tabla o
reconoce la entrega ya aplicada. Un conflicto detiene todo antes de escribir.
La prueba final pendiente en el equipo afectado consiste en reabrir el CRM
sin llamada activa, abrir Nueva llamada, moverlo por su encabezado, cambiar
el tamaño de ventana y realizar una llamada normal. La cobertura de esta
entrega es la interfaz compartida del teléfono en Chrome 86 y navegadores
actuales; no es una garantía universal sobre cualquier navegador antiguo.
