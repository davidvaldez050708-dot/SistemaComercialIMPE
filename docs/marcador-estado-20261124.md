# Marcador después de registrar resultado

El marcador conservaba el último mensaje de llamada al recibir un estado inactivo:
«Llamar» aparecía habilitado junto a «Llamada en curso…». Además, el resumen
finalizado bloqueaba la marcación aunque su resultado ya estuviera guardado.

La interfaz limpia los mensajes del teléfono al volver a reposo, conserva las
validaciones de entrada y permite marcar tras el acuse del resultado guardado.
Notifica al shell la referencia y extensión que se guardaron; el shell instalado
mantiene sus comprobaciones del propietario y diálogo. Una llamada activa,
un resultado pendiente o un guardado fallido conservan el bloqueo. La corrección
no fuerza el cierre de llamadas ni cambia SIP, permisos o registros de servidor.

Validación: Chrome con estados simulados sobre la copia instalada del marcador;
reproducción del texto retenido; cierre/reposo; validaciones; guardado 200 y 503;
guardado demorado mientras existe otra llamada activa. El guard de autorización
comprueba que no se inicia WebRTC antes de aceptar el POST de marcación.
No se realizaron llamadas ni se enviaron mensajes reales.

Publicación: `tools/publicar_marcador_estado.py` y su manifiesto aplican ocho
bloques de un único JS. Probados contra la copia instalada con personalizaciones,
LF/CRLF, comprobación sin escritura, aplicación repetida, restauración y rechazo
de conflictos. Compatible con Python 3.6. Respaldo obligatorio fuera de public_html;
no reemplazar el archivo completo con la versión del repositorio.

Esta corrección cubre los dos estados de interfaz reproducidos. Si persiste un
bloqueo con el teléfono realmente activo, se necesita examinar ese diálogo y
su confirmación de cierre, sin liberar el botón a ciegas.
