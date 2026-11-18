# Alertas sonoras y del navegador en Agenda

Rama: `codex/agenda-alertas-sonoras`. Base de integración: `714ce7d1`.

## Funcionamiento

La consulta autenticada existente de `ReminderController::pendientes` sigue
siendo la fuente de permisos y notificaciones. No se crean tablas, cron, servicios
externos, Web Push ni un segundo sondeo HTTP. Se conserva el intervalo de consulta
existente de 60 segundos; un reloj local comprueba los horarios cada segundo.
Se conserva el control de consultas en curso y de refrescos comerciales de la
versión instalada. Web Locks coordina la entrega multimedia entre pestañas.

La configuración está en Agenda → **Notificaciones de la Agenda**. Permite
sonidos, anticipación de 5/10/15/30 minutos (10 por defecto), notificaciones del
navegador y prueba del audio. Se guarda automáticamente en `localStorage`, bajo
una clave por ID de usuario. No se guarda información de actividades en las
preferencias. Los textos de las alertas permanecen en memoria.

`agenda_alertas.js` consume los horarios de reuniones y comentarios ya autorizados,
y las fechas de seguimientos que devuelve la campana. Usa timestamps calculados
por PHP y la zona horaria del servidor. La alerta previa se emite al entrar en la
ventana configurada, y otra al alcanzar la hora. Si la pestaña se abre dentro de
esa ventana, indica el tiempo realmente restante. Se recupera el vencimiento
con un margen máximo de 90 segundos; no revive vencimientos antiguos.

Las reuniones confirmadas se consultan hasta 90 segundos después de su hora para
detectar el vencimiento entre consultas. Esto amplía una consulta existente de
solo lectura; no altera el estado de las reuniones. Los seguimientos mantienen
el alcance, límite y permisos de la campana actual.

Los comentarios nuevos se reconocen por su fecha de creación y autor. La primera
conexión establece la referencia sin anunciar el historial como nuevo. Se
notifican comentarios posteriores de hasta cinco minutos de antigüedad; se
excluyen los comentarios del propio usuario y los acuses de lectura. Se mantiene
la presentación interna existente de comentarios y su mecanismo de lectura.

La deduplicación guarda solo identificadores y timestamps, con máximo de 1,000
entradas y siete días. Las claves incluyen evento, horario y anticipación.
Entrega visual, entrega multimedia y lectura son estados separados. Los avisos
que requieren atención nunca se marcan leídos por un temporizador. Las alertas
programadas usan el presentador existente; esperan a que termine otro toast.

## Audio y compatibilidad

Recurso: `public/audio/agenda-aviso.mp3`, sonido original de aproximadamente 0.6
segundos, CC0 (`public/audio/LICENSE-agenda.txt`). Volumen: 70%. Se sintetizaron
tonos de 660/880 Hz; el codificador temporal no se instala en el CRM.

Se usa `HTMLAudioElement`, sin sonidos superpuestos y con un margen de tres
segundos entre reproducciones. El navegador puede exigir un gesto previo. El
botón **Probar sonido** permite comprobarlo. No se reproducen después sonidos
antiguos bloqueados por autoplay. Una alerta pendiente conserva su intento de
audio hasta 90 segundos si el navegador bloqueó la reproducción; sólo se registra
como reproducida después de que `play()` tenga éxito. Un gesto permite reintentar.
La prueba del botón indica si realmente se inició el audio y no utiliza una
reproducción muda concurrente. Una carga de audio que no responde se corta a los
tres segundos para liberar la coordinación entre pestañas.

Notifications API solo solicita permiso al activar el interruptor. Requiere
HTTPS/localhost y soporte del navegador. Un rechazo o error conserva los avisos
internos. La notificación usa el favicon institucional y un enlace del mismo
origen. Los avisos funcionan con la aplicación abierta, también en segundo plano
si se habilitan notificaciones del navegador. No funcionan con el navegador
cerrado. La suspensión de pestañas o del sistema puede retrasarlas.

Web Locks ofrece exclusión entre pestañas en navegadores compatibles. Sin esta
API, el registro compartido reduce duplicados pero no garantiza exclusión ante
dos entregas exactamente simultáneas. Si se bloquea el almacenamiento, las
preferencias y deduplicación solo duran en la pestaña. Un fallo de consulta por
más de 90 segundos pausa las alertas programadas hasta recibir datos actuales.

## Validación aislada realizada

- Prueba de navegador `tests/agenda_alertas_browser.cjs`: anticipación, hora de
  vencimiento, sonido activado, preferencias, persistencia y separación por
  usuario, comentarios antiguos/nuevos y autor, permisos aceptados/denegados,
  almacenamiento bloqueado, varias pestañas, decodificación del MP3 real e
  integración con `recordatorios.js`, campana y toast existentes.
- Suite de ocho escenarios `agenda_comentarios_avisos_browser.cjs`: presentación
  y lectura de comentarios, CSRF, no repetición y cadencia existente conservadas.
- PHP: comentarios, destinatarios, privacidad, permisos por rol y revocación,
  avisos de solicitud/confirmación; sin base de datos productiva.
- Sintaxis PHP/JavaScript y comprobación de diff.
- `tests/agenda_alertas_publicador_test.py`: comprobación sin escrituras en el
  sitio, integración de una personalización, respaldo, huellas, restauración
  exacta y rechazo ante un archivo personalizado en conflicto.

El audio y los permisos del SO se simulan en la prueba funcional. La decodificación
del MP3 se verifica con Chromium real. Queda la prueba humana de audibilidad,
permisos del SO, dispositivos móviles y una reunión/comentario reales en un
entorno de pruebas instalado. No se declara una validación completa de todos los
módulos comerciales: no se modificaron y no se ejercitaron todos sus recorridos.

## Publicación bajo revisión

No se ha modificado Hostinger. `tools/publicar_agenda_alertas.py` contiene una
lista cerrada de archivos, integración de tres versiones y comprobación de
sintaxis PHP. Se detiene ante archivos nuevos en conflicto o personalizaciones
incompatibles. `--check` no escribe en el sitio. `--apply` exige respaldo fuera
del sitio público, comprueba huellas antes y después y revierte ante error.

Para restaurar, usar el mismo publicador con `--site RUTA --restore RESPALDO`.
La restauración valida las huellas instaladas y las copias, y se detiene si hubo
cambios posteriores. No ejecuta SQL ni modifica configuración privada.

## Conciliación con Hostinger, 18 de noviembre de 2026

La copia proporcionada por el usuario de `recordatorios.js` coincide exactamente
con SHA256 `d96c236c6a39918b08a2e04390a30575e49358015412069ee6329ac31eaab99b`.
Se conserva como base de conciliación en
`tests/fixtures/recordatorios_hostinger_20261118.js`. Incluye avisos comerciales,
reconsulta tras guardar resultados, conservación durante respuestas en curso,
coordinación comercial entre pestañas y toasts de ocho segundos. La integración
mantiene esos bloques. El publicador usa esta base sólo para ese archivo y se
detiene si otra personalización entra en conflicto.

El diagnóstico de SSH mostró que faltaban `agenda_alertas.js` y el MP3: las
comprobaciones previas no los habían instalado. Eso explica la ausencia del nuevo
sonido, pero no demuestra la causa de un fallo de notificaciones en el servidor.
Las pruebas locales verifican campana y toasts con el componente opcional ausente,
con una excepción simulada y con metadatos de comentarios de una versión anterior.
También verifican el refresco comercial simultáneo, audio real en Chromium y la
recuperación de un intento bloqueado, sin consumirlo como si se hubiera escuchado.

Archivos de aplicación modificados: `ReminderController.php`, `ReminderHelper.php`,
`AgendaComentarioService.php`, `AgendaReunionService.php`, `dashboard_footer.php`,
la vista `seguimiento_vinculacion/agenda.php` y `recordatorios.js`.
Archivos nuevos de aplicación: `agenda_alertas.js`, MP3 y licencia.
Se añaden este documento, la prueba de navegador y el publicador selectivo.
