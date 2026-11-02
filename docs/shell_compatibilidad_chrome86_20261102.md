Compatibilidad de dimensiones del CRM en Chrome 86
=================================================

El usuario confirmó Chrome 86.0.4240 y estos hashes de Hostinger:

- public/css/telefonia_shell.css:
  dbd54f7a6386a785974de30fae99a12195c84d5f326de589468af6e035bddc27
- app/views/telefonia/shell.php (se conserva sin modificar):
  cb0a8e99224341fa283e89dbd9f5498433723ffaf2052eb487c8112116ac2e7f

La hoja publicada se obtuvo también por su URL pública y coincidió exactamente
con el hash informado por SSH. La fotografía muestra el documento del shell
con data-impe-telephony-shell. La segunda foto tiene además la emulación de
dispositivo de Chrome activada a 499 x 816; eso debe desactivarse para probar
la vista de escritorio, pero no resuelve la incompatibilidad de CSS.

Causa reproducida
-----------------

El contenedor fijo del CRM dependía exclusivamente de inset:0 para estirarse
al viewport. Chrome incorporó inset en la versión 87. En la prueba con el CSS
publicado, al ignorar esa propiedad el iframe toma su tamaño intrínseco de
300 x 150 y los documentos internos activan correctamente su responsive móvil
debido a ese ancho equivocado. Login es otro documento y no usa ese marco.

Fuente primaria de compatibilidad consultada:
https://github.com/mdn/browser-compat-data/blob/main/css/properties/inset.json

La corrección agrega un bloque al final de la misma hoja, conservando todos
los bytes anteriores. Sólo dentro de html[data-impe-telephony-shell]:

- Fija los cuatro bordes con top/right/bottom/left y dimensiones 100%.
- Proporciona los cuatro bordes de la máscara de recuperación de navegación.
- En navegadores sin dvh usa alturas y límites equivalentes con vh para
  mantener un viewport útil del teléfono y los paneles, sin cambiar su lógica.

No modifica PHP, JS, breakpoints del dashboard, estado de llamadas, permisos,
usuarios, base de datos ni grabaciones. No fuerza un ancho de escritorio en
móviles. No constituye una actualización ni emulación de todo Chrome antiguo.

Validación
----------

`node tests/shell_compatibilidad_viewport_browser.cjs` utiliza Chrome actual
y un modelo acotado de reglas CSS ignoradas en Chrome 86 (inset y dvh). No
pretende ser una ejecución del binario de Chrome 86. Reproduce la regresión
antes del cambio y comprueba después 16 combinaciones de escritorio, tablet,
móvil y densidad: 1366, 1280, 1024, 768, 499, 375 y 320 px. Se verifican
viewport de padre/iframe, media query interna, scroll del módulo, máscara de
navegación y dimensiones del motor telefónico.

`python tests/publicacion_compatibilidad_shell_test.py` verifica que únicamente
se cambie el CSS esperado, que se conserven las personalizaciones anteriores,
LF/CRLF, check sin escrituras, respaldo/restauración exacta e idempotencia.

Publicación
-----------

El manifiesto admite sólo la versión de CSS comprobada o la versión corregida.
Una diferencia desconocida detiene la instalación antes de escribir. Se
conservan los saltos de línea y el respaldo queda fuera de public_html.
Restaurar mediante --restore RUTA usando el mismo manifiesto.

Prueba final en el equipo afectado: cerrar la emulación móvil/F12, Ctrl+0,
mantener una sola pestaña del CRM y recargar sin caché después de instalar.
El aviso de reserva telefónica de la primera foto es independiente del tamaño
del marco y puede aparecer porque hay dos pestañas del CRM abiertas.
