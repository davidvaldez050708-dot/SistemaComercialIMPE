# Doble sonido y avisos de escritorio

Rama: `codex/agenda-doble-sonido-escritorio`. Base: `bd8c272d`, verificada contra
los dos scripts públicos de `fundacionrededumex.org` antes de preparar el cambio.
La actualización de aplicación sólo modifica `agenda_alertas.js` y `recordatorios.js`.

## Cambios

- El mismo MP3 local se reproduce dos veces consecutivas, esperando el evento
  `ended` antes de iniciar la segunda reproducción. Volumen HTMLAudioElement:
  100%, antes 70%. El volumen del sistema operativo y de la pestaña sigue siendo
  controlado por el usuario.
- Cada toast de Agenda dura cuatro segundos más: comentarios, 6.6 segundos;
  reuniones, 12 segundos. Se conserva el funcionamiento y la duración de las
  notificaciones comerciales y telefónicas.
- La notificación de escritorio pide `requireInteraction: true` y programa su
  cierre a los mismos 6.6/12 segundos. Windows y cada navegador pueden decidir
  ocultar antes el banner o mostrarlo en su centro de notificaciones; la API no
  permite garantizar cuatro segundos adicionales de banner en todos los equipos.
- Si el aviso llega con la pestaña del sistema en segundo plano, se muestra el
  aviso de escritorio y se solicita el mismo par de sonidos. Al regresar al
  sistema, el toast no vuelve a reproducir el par ya entregado.
- Se usa la API estándar `Notification`, con comprobación de soporte y contexto
  seguro, sin detección ni dependencia de marca de navegador. Si el permiso ya
  está concedido, se reutiliza. Si sigue pendiente, se solicita una sola vez por
  usuario al hacer un clic normal dentro de la Agenda. No se solicita al cargar
  la página ni se insiste si el usuario lo rechazó.

La aplicación debe estar abierta en alguna pestaña con sesión vigente; se puede
usar otra pestaña, navegador u otra aplicación. No se implementa Web Push, y no se
prometen avisos con el navegador cerrado, la sesión terminada o la pestaña
suspendida por el navegador/sistema operativo. En navegadores sin soporte se
mantienen las alertas internas. Los permisos denegados deben cambiarse en la
configuración del navegador. No se agregan tablas, servicios externos ni sondeos.

## Validación aislada

- `tests/agenda_doble_sonido_escritorio_browser.cjs`: ejecutado en Chrome
  157.0.8103.79, Brave (Chromium 157.0.8103.79) y Edge 157.0.4341.60.
  Comprueba par de sonidos, volumen, no superposición local, avisos con pestaña
  oculta, deduplicación al regresar, cierre programado, permisos y toast extendido.
  Las API de audio/notificaciones del SO se simulan en esta prueba.
- `tests/agenda_alertas_browser.cjs`: conserva 30/10/5/1 minutos y vencimiento
  para reunión/comentario; reproduce el MP3 real dos veces con Chromium y comprueba
  volumen 100%, sin nuevas activaciones internas.
- `tests/agenda_comentarios_avisos_browser.cjs`: ocho escenarios existentes de
  comentarios, lectura con CSRF, deduplicación, recurrencia y duración de 6.6 s.
- `tests/agenda_doble_sonido_publicador_test.py`: paquete de sólo dos scripts,
  check sin escrituras, preservación de personalización, integridad, respaldo,
  restauración y rechazo ante conflicto.

Firefox y Safari no están instalados en este laboratorio; no se declara prueba
real en esos navegadores. Se usa su API estándar cuando esté disponible. La
audibilidad física y la permanencia del banner nativo requieren comprobación
humana en cada equipo; las pruebas de navegador se realizan sin interfaz visible.

## Publicación manual

`tools/publicar_agenda_doble_sonido.py` publica exclusivamente los dos scripts,
con base en la versión que ya funciona en Hostinger. `--check` no cambia el sitio;
`--apply` requiere respaldo fuera de `public_html`, verifica huellas y revierte
ante errores. `--restore RESPALDO` valida la integridad antes de restaurar.
Se detiene si una personalización produce conflicto. No se ha publicado en
Hostinger automáticamente.
