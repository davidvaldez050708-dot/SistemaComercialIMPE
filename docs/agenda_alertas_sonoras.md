# Alertas automáticas de Agenda

Rama: `codex/agenda-alertas-sonoras`.

## Comportamiento vigente

Las alertas están integradas al sistema. No hay panel de activación, interruptor,
selector de anticipación ni botón de prueba. Los ajustes anteriores de
`localStorage` no desactivan el sonido ni cambian la cadencia.

Se muestran toasts a los **30, 10, 5 y 1 minuto antes** de la fecha y hora de
reuniones y comentarios programados autorizados para el usuario. También se
conserva el aviso al llegar la hora. No son intervalos indefinidos de repetición:
cada anticipación se entrega una vez por evento y horario. No se avisa con un día
de antelación si la actividad pertenece al día siguiente.

El sonido se solicita después de que el presentador confirma que el toast está
visible. Si otro toast ocupa el espacio, la alerta espera y aún no suena.
Los comentarios nuevos utilizan el mismo aviso visual existente; su sonido se
engancha a ese presentador. La lectura y la entrega multimedia permanecen
separadas; un temporizador no marca avisos como leídos.

Se reutiliza `ReminderController::pendientes`: misma sesión, permisos y fuente de
datos. No hay otro sondeo HTTP, tablas, cron ni dependencias de ejecución. La
campana sigue consultando cada 60 segundos y un reloj local evalúa horarios cada
segundo. Se usan timestamps y zona horaria del servidor. Se conservan las
notificaciones comerciales, su refresco tras resultados y sus toasts de ocho
segundos. Los comentarios conservan su duración existente.

## Audio y compatibilidad

Archivo original CC0: `public/audio/agenda-aviso.mp3`, aproximadamente 0.6 segundos,
volumen 70%. Licencia: `public/audio/LICENSE-agenda.txt`.

No se necesita activar controles. El navegador puede exigir un primer clic o
tecla normal en el sistema antes de permitir reproducción automática. Esa
restricción no se puede evadir desde JavaScript. Si bloquea audio, se conserva
el intento por hasta 90 segundos y se reintenta tras un gesto normal, sin
consumirlo como si se hubiera reproducido. No se superponen sonidos. Una carga
que no responde se corta a los tres segundos.

No se solicita permiso de notificaciones del sistema operativo. Si ya estaba
concedido, se puede reutilizar; la campana y los toasts funcionan sin él. La
aplicación debe permanecer abierta. La suspensión de pestañas o del equipo
puede retrasar avisos; no se implementa Web Push ni alertas con navegador cerrado.

Web Locks coordina entregas entre pestañas. La deduplicación usa identificadores
por usuario, evento, horario, fase y canal (visual/audio/navegador). Sin Web Locks
el registro compartido reduce duplicados pero no garantiza exclusión exacta.
Sin almacenamiento persistente, el registro sólo dura en la pestaña. Los
sondeos conservan su control de consulta en curso y la reconsulta comercial.
Un fallo del componente opcional no interrumpe la campana ni el comportamiento
anterior de sus avisos.

## Base real y publicación

La copia proporcionada de Hostinger coincide con SHA256
`d96c236c6a39918b08a2e04390a30575e49358015412069ee6329ac31eaab99b`.
Se conserva en `tests/fixtures/recordatorios_hostinger_20261118.js` como base
selectiva de conciliación de `recordatorios.js`. La vista y el módulo multimedia
se concilian también contra la entrega anterior `7b53aa46`, para retirar el panel
y actualizar instalaciones existentes sin sobrescribir personalizaciones.

`tools/publicar_agenda_alertas.py` usa una lista cerrada de archivos y mezcla de
tres versiones. `--check` no escribe en el sitio. `--apply` exige respaldo externo,
verifica huellas antes y después y revierte ante errores. `--restore RESPALDO`
comprueba integridad y se detiene ante modificaciones posteriores. No ejecuta SQL.
No se ha publicado automáticamente en Hostinger.

## Pruebas

`tests/agenda_alertas_browser.cjs` ejecuta `agenda_alertas_cadencia_browser.cjs`:
reunión y comentario a 30/10/5/1 minutos y al vencimiento; sonido por cada toast;
no repetición por consultas; deduplicación entre pestañas; audio tras primer gesto;
espera si el toast no puede mostrarse; ajustes antiguos ignorados; integración con
campana y comentarios; ausencia del panel; reproducción real del MP3 en Chromium.
El audio del SO requiere comprobación humana en los equipos de destino.

Se ejecuta también la suite existente `agenda_comentarios_avisos_browser.cjs`,
con ocho escenarios de lectura, CSRF, duración, recurrencia y no repetición.
`agenda_alertas_publicador_test.py` verifica comprobación sin escrituras,
preservación de personalización, instalación nueva y actualización desde la
versión anterior, respaldo, restauración y rechazo de conflicto.

No se han ejercitado todos los módulos comerciales ni dispositivos móviles.
No se realizan pruebas sobre la base de datos ni telefonía de producción.
