# Estado de llamadas transferidas al Analista

El observador del Analista distingue el timbrado del widget de la confirmación
SIP. En el SDK Zadarma v8, el aviso push puede hacer sonar el teléfono y mostrar
el número entrante antes de que exista una nueva `webCallSession`. La sesión se
crea al atender. Una referencia a una llamada anterior terminada no debe impedir
que el CRM publique el nuevo timbrado y muestre el botón Contestar.

El timbrado requiere las señales entrantes del widget y un número no vacío. La
conversación sólo se confirma consultando `isEstablished()` de la sesión JsSIP
entrante observada. `isEnded()` finaliza esa sesión incluso si el SDK ha retirado
su referencia global. Las clases de los iconos no confirman respuesta: en el
widget actual `call-btn-up` corresponde a reposo y `incoming` puede conservarse
durante la conversación.

Después del cierre, un DOM que conserva las mismas marcas no inicia otra llamada.
Un nuevo timbrado después de retirar esas marcas sí debe abrir el aviso, aunque
sea del mismo número y todavía no exista la siguiente sesión SIP.

## Validación

Ejecutar con Node.js desde la raíz:

```sh
node tests/telefonia_sdk_entrantes_observador_smoke.js
node tests/telefonia_transferida_estado_sip_smoke.js
node tests/telefonia_timbrado_antes_sip_smoke.js
node tests/telefonia_analista_estabilidad_smoke.js
```

Las pruebas cubren el push anterior a SIP, una referencia terminada, llamadas
consecutivas del mismo número, negociación, respuesta, DOM atrasado, rechazo y
cierre sin reactivación del cronómetro. El flujo de GitHub Actions de Entrantes
Analistas incluye las dos nuevas pruebas.

## Hostinger

Respaldar y sustituir únicamente
`public/javascript/telefonia_sdk_entrantes_observador.js`. Conservar las
personalizaciones publicadas en los otros archivos. Actualizar GitHub no confirma
que Hostinger haya recibido el cambio; verificar el contenido servido y recargar
el host telefónico sin llamadas activas.

La validación real sigue requiriendo transferir una llamada saliente de Marketing
100 a Analista 101, comprobar que el modal aparece antes de contestar y verificar
audio en ambos sentidos, cronómetro y cierre. Repetir con otra llamada y con una
rechazada. La confirmación SIP no demuestra por sí sola audio audible ni recepción
de paquetes RTP.
