# Conexión telefónica persistente — 24 de octubre de 2026

## Estado de la entrega

Implementación y simulaciones terminadas; **no instalada en Hostinger y no validada con llamadas reales**. Sin SQL. No confundir los saltos de reloj de las pruebas con una hora efectiva de recepción Zadarma. La aceptación operativa requiere los ensayos descritos al final, para los tres perfiles.

## Base auditada y personalizaciones

Rama `fix/telefonia-conexion-persistente`, basada en `c608387b41270ee014083c5810167dd1a8466323`, último estado de [PR 133](https://github.com/davidvaldez050708-dot/SistemaComercialIMPE/pull/133). Se revisaron [PR 125](https://github.com/davidvaldez050708-dot/SistemaComercialIMPE/pull/125), [PR 116](https://github.com/davidvaldez050708-dot/SistemaComercialIMPE/pull/116) y los commits recientes de cierre SIP/resultado. Los motores de esa base conservan los cambios de PR 125; no se tomó el `main` anterior como código operativo.

Los hashes de Hostinger y los diffs proporcionados se reconstruyen y comprueban en `tools/preparar_telefonia_conexion.py`. La variante de `telefonia_persistente.js` proviene de los parches ya verificados de Ventas A. Se preservan exactamente su recepción de Marketing/Analista, sus controles retirados y la consulta del shell sólo para Ventas. La variante de shell conserva su `getCallState()` y su comentario de confirmación SQL. La del host conserva la recuperación `onConnected` perdida y el contador en cero durante `answering`. Ambas vistas y el audio coinciden con la base identificada. Login y controlador tienen versiones diferentes pero **no se publican ni modifican**.

## Causas encontradas

1. La propiedad se consideraba vencida después de 9 segundos y dependía de una renovación cada 3 segundos. Una pestaña ralentizada podía seguir teniendo WebRTC mientras otra abría un segundo motor.
2. Estados de 3.5/5.5 segundos mezclaban frescura de un mensaje local con estado real de red. El shell recreaba el iframe por ausencia de heartbeat después de un minuto.
3. `hostReady=widgetReady` confirmaba que existía una API JS; no acreditaba un transporte operativo. Se podía dejar en verde un widget desconectado.
4. Una carga de script fallida podía permanecer en el DOM y dejar esperando a posteriores intentos. La petición inicial de credenciales no tenía plazo de espera.
5. Una preferencia antigua de recepción podía impedir el arranque. El shell no iniciaba automáticamente las extensiones sólo salientes.

## Arquitectura real del proveedor

Se inspeccionaron los recursos públicos cargados por el CRM: `loader-phone-lib.js?v=23`, `loader-phone-fn.js?v=23`, `widget-api.min.js?sub_v=68` y Socket.IO **2.3.0**. En reposo, `reg()` abre `/webrtc_pushes` en `ws.zadarma.com`; la sesión JsSIP se crea al llamar/contestar. **Ausencia de registro SIP en reposo no demuestra caída**. No se fuerza un SIP paralelo ni se llama `regToCall()` para aparentar disponibilidad.

`telefonia_conexion.js` observa, mediante un Proxy de la función `io` de este iframe, la instancia devuelta para ese dominio/namespace exactos. Conserva argumentos, retorno y propiedades de la función; no reemplaza métodos del widget, SIP, sockets, audio ni mensajes. Escucha `init` del servidor, `pong`, desconexión y errores, sin almacenar URL, claves o contenido de llamadas. `socket.connected` sin `init` no acredita conexión. Tras 90 segundos sin evidencia del canal push, se considera sin verificar hasta recibir evidencia nueva. Para SIP se consultan `webPhoneUA.isRegistered()` **y** `isConnected()`; el modo se informa por separado.

Fuentes primarias:

- [Integración oficial Zadarma](https://zadarma.com/en/support/instructions/crm-zadarma/crm-zadarma/) y [API / WebRTC: clave de 72 horas](https://zadarma.com/en/support/api/).
- [Socket.IO 2: socket.connect, connected, eventos y managers](https://socket.io/docs/v2/client-api/).
- [JsSIP UA: conexión, registro y recuperación](https://jssip.net/documentation/api/ua/).
- [Web Locks](https://developer.mozilla.org/en-US/docs/Web/API/Web_Locks_API) y [ralentización de temporizadores en Chrome](https://developer.chrome.com/blog/timer-throttling-in-chrome-88/).

Huellas de los recursos observados, sin redistribuirlos:

| Recurso | SHA-256 |
| --- | --- |
| loader-phone-lib.js?v=23 | 8fb1086297a5216593df4489f8eb2267fd6509ce415c09aa2fa52c969015afd3 |
| loader-phone-fn.js?v=23 | 53a247accc366d09c164c53b5b39c347cc0a28d65c5a74f4bf782ec94c2dfd47 |
| widget-api.min.js?sub_v=68 | 4fb79f0b852ad9b0bbd3ec0f7b15f1fc3477cfe4e7e5792321c3db55e9965c63 |
| socket.io.js | fb7bd3f8e05835e2b81aacb7207cedd7e908f52f46fbae03e72c1908da987b6a |

## Comportamiento corregido

- El login conserva sus permisos y resolución de extensión. Al entrar al shell se inicia automáticamente el motor autorizado, también si sólo permite salientes. No se conceden permisos a perfiles sin extensión activa.
- Web Locks mantiene una reserva durante la vida del documento, sin caducidad por timers. Dos pestañas nuevas se encolan; al cerrar/destruirse el dueño, el navegador entrega la reserva. Un teléfono abierto directamente también participa. Una pestaña en espera no publica sobre el estado del dueño.
- No hay robo de propiedad por timeout ni `steal`. Una pestaña del software anterior no participa en Web Locks: por eso la instalación exige cerrar todas las pestañas telefónicas anteriores. Sin Web Locks se usa una reserva conservadora sin expiración; no se garantiza recuperación de crash en ese modo antiguo. Se recomienda Chrome/Edge actual con HTTPS.
- El motor se conserva al cambiar módulo, foco, visibilidad o minimizar. Focus, pageshow, online y visibilitychange consultan salud y resincronizan; no desmontan el iframe ni cuelgan.
- La disponibilidad consulta directamente la salud del host, además de sincronizar módulos por BroadcastChannel/localStorage. El estado en caché de una pestaña secundaria expira conservadoramente. Una llamada activa no se descarta sólo porque su publicación se retrase.
- Se distinguen SDK cargado, canal push verificado y SIP registrado. El verde exige transporte acreditado, micrófono autorizado y contexto de audio en ejecución. Un bloqueo de permisos muestra el aviso del CRM y «Activar teléfono»; la activación solicita permiso mediante el navegador, sin eludir sus restricciones.
- Las inicializaciones y comprobaciones en curso se comparten. Fallos de inicio reintentan a 5, 10, 20, 40 y 60 segundos, sin detenerse por un error antiguo; la recuperación de socket reutiliza `socket.connect()` con backoff hasta 60 segundos. El éxito limpia errores/reintentos. La política nativa del proveedor también continúa.
- Sin red no se intentan nuevas conexiones. Al volver, se revalida y reutiliza el motor. Una llamada viva, incluso acreditada sólo por la RTCSession, impide acciones de recuperación que cambien su transporte. No se termina la PBX por una caída de red.
- Credenciales: espera máxima inicial de 15 segundos. Scripts: espera de 20 segundos; un recurso lento se reutiliza aunque llegue tarde; un error real permite volver a descargarlo. No se recarga el SDK periódicamente para mantenerlo vivo.

## Archivos de ejecución

| Archivo | Motivo |
| --- | --- |
| public/javascript/telefonia_conexion.js | Evidencia y recuperación del transporte real del proveedor. |
| public/javascript/telefonia_host.js | Salud, inicio único, permisos, reintentos, tiempos de espera y reserva de teléfono directo. |
| public/javascript/telefonia_shell.js | Reserva Web Locks, consulta directa del host y resincronización sin reemplazos. |
| public/javascript/telefonia_persistente.js | Fallback del indicador exige evidencia de conexión. |
| public/javascript/telefonia_audio.js | Expone el estado del AudioContext existente; no cambia tonos ni pistas. |
| app/views/telefonia/host.php | Carga el observador antes del motor. |
| app/views/telefonia/shell.php | Inicia también extensiones sólo salientes autorizadas. |

No se cambian transferencias, grabaciones, asignaciones, SQL, API keys, expedientes, resultados, notificaciones ni agenda. Se ejecutan regresiones del código compartido para detectar impactos.

## Evidencia automatizada

`tests/telefonia_conexion_browser.cjs` usa Edge local y Chromium en CI, PHP de las vistas reales, Web Locks del navegador y transporte/API sintéticos. Las credenciales son ficticias. Ejecuta 153 comprobaciones sobre código GitHub y otras 153 sobre las variantes exactas de Hostinger: tres perfiles, sólo salientes, dos errores seguidos al preparar clave, carga del SDK sin transporte, push y SIP separados, saltos de reloj de 5/15/30/60 minutos, ausencia de timers intermedios, regreso y evidencia nueva, dos pestañas, teléfono directo, cierre real del dueño, offline/online, protección de RTCSession viva, audio bloqueado y activación, conservación del iframe al navegar.

`tests/telefonia_conexion_smoke.cjs` verifica backoff, errores consecutivos, recuperación, socket anterior/ajeno, init/pong, SIP, carga tardía única, plazo de scripts y recuperación de descarga fallida. Los smokes existentes verifican el modal y resultados consecutivos, transferencias directa/consultada/devolución, DTMF, timbrado antes de SIP, fin exacto 100→150 sin inventar END PBX, contexto/audio y navegación.

`tests/publicacion_telefonia_conexion_test.py` prueba base, Hostinger, CRLF, instalación parcial, interrupción, idempotencia, restauración, conflictos y Python 3.6 en réplicas. El publicador exige los hashes exactos conocidos; una variante nueva bloquea la publicación completa y requiere conciliación.

Workflow dirigido: `.github/workflows/telefonia-conexion-persistente.yml`. Consultar su resultado real en el PR. No afirmar que todos los workflows históricos del repositorio estén aprobados basándose sólo en este job.

## Validación real pendiente: aceptación operativa

Hacer los ensayos con extensión y destinatarios de prueba autorizados. Registrar hora, perfil/extensión, navegador, escenario, timbrado real, contestación, audio bidireccional y resultado. No basta ver verde.

1. Marketing/Recepción: login limpio; recepción sin abrir Mis llamadas. Saliente, entrante, colgar, llamada consecutiva y transferencias directa/consultada.
2. Analista: mismo arranque; recibir 100→101, contestar, audio bidireccional, resultado y expediente; verificar audios por tramos existentes.
3. Asesor: recibir 100→150, saliente propia, resultado comercial y grabaciones; conservar el comportamiento previo cuando falta END PBX.
4. Para cada perfil: otra pestaña 15 minutos, otra aplicación 30 minutos, segundo plano 60 minutos. Durante cada ausencia enviar una llamada de prueba. **Debe sonar y poder contestarse sin recargar**. Conservar evidencia del canal, audio y ausencia de registros SIP competidores.
5. Abrir dos pestañas; sólo una crea el motor. Minimizar/cambiar foco no debe transferir la reserva. Cerrar el dueño en reposo debe habilitar a la otra. No cerrar un dueño con llamada en curso como prueba de recuperación: eso destruye físicamente su audio.
6. Interrumpir y restaurar red en reposo; debe recuperarse. Repetir con llamada activa y comprobar que CRM no inventa su cierre, no duplica registro y concilia la situación real de PBX.
7. Validar permisos denegados de micrófono/audio y reactivación desde el aviso del sistema. Probar módulo Inicio/Seguimiento/Mis llamadas sin crear otro iframe.

## Límites

Web Locks coordina pestañas del mismo origen y perfil del navegador; no coordina equipos, perfiles distintos ni dos navegadores. Suspender el PC, cerrar el navegador, congelar/descartar una pestaña desde el sistema, perder red o tener una caída del proveedor impide prometer recepción permanente. Al reanudarse se comprueba la conexión sin simular disponibilidad. La clave WebRTC tiene vigencia definida por Zadarma; una sesión que exceda esa vigencia puede requerir renovar la sesión del proveedor. No se implementa un segundo cliente SIP ni un servicio de telefonía en segundo plano independiente del navegador.

El SDK remoto puede cambiar. Si ya no ofrece la evidencia observada, la disponibilidad queda sin verificar: se debe revisar la integración, no forzar el verde. Un canal verificado y AudioContext activo no prueban volumen físico, altavoces ni una llamada concreta: por eso son obligatorias las pruebas reales de aceptación.
