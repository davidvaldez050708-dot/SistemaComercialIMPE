# Micrófonos: piloto Prueba.llamadas / extensión 155

> Documento de la primera entrega. El usuario instaló esa versión y confirmó su funcionamiento. La ampliación autorizada a extensiones activas, el acceso del encabezado y el aviso previo se describen en [microfonos_encabezado.md](microfonos_encabezado.md). Para habilitar esa ampliación se necesita `scope => 'assigned'`; sin esa opción se conserva el piloto.

## Estado y alcance

Implementación para revisión, **sin despliegue ni activación en producción**. Bandera privada apagada por defecto. La comprobación de servidor exige simultáneamente sesión autenticada, rol 3, usuario `Prueba.llamadas` (sin distinguir mayúsculas) y extensión `155`. No se amplía por parámetros del navegador.

Origen: rama `fix/telefono-flotante-compatibilidad`, commit `e51564dbcf18401ac90e86e2e2c8273b3d1f6e9b`. Rama de trabajo: `feat/microfonos-piloto-155`. El PR se compara con esa rama para separar este cambio de las correcciones anteriores.

## Diagnóstico e investigación

El caso comunicado demuestra una selección incorrecta: Windows muestra Logi USB y el sender WebRTC utiliza Realtek. No demuestra por sí mismo la causa del sonido hueco. No se modifican cancelación de eco, supresión de ruido, ganancia, canales ni frecuencia.

En los archivos públicos instalados que se consultaron el 2 de noviembre de 2026, `telefonia_host.js` **no contiene `captureMicrophone()` ni una envoltura de getUserMedia**. Su control `syncMicrophone()/setMuted()` obtiene las pistas de `webCallSession.connection.getSenders()` y cambia exclusivamente `enabled`. `activate()` hace una captura voluntaria breve y la detiene. Se conservan ambas funciones literalmente.

Se revisaron el host, shell, cliente persistente, marcador, audio, observador de entrantes y runtime de transferencias. `telefonia_audio.js` gestiona el tono de espera y analiza audio recibido; no selecciona la entrada. El motor continúa en el mismo iframe persistente, protegido por la reserva existente. No hay modificaciones a PBX, webhooks, resultados, grabaciones, notificaciones, credenciales, Caller ID ni permisos.

En el SDK oficial servido por Zadarma v8, `widget-api.min.js?sub_v=68` llama a JsSIP con `mediaConstraints:{audio:true,video:false}` al llamar y al contestar. `jssip.min.js?v=7` utiliza `navigator.mediaDevices.getUserMedia(i)`. No se encontró un selector público de micrófono documentado en las páginas de integración consultadas. Esto describe la versión examinada; no garantiza APIs de versiones futuras.

Fuentes primarias:

- [Integración oficial WebRTC en CRM](https://zadarma.com/en/support/instructions/crm-zadarma/crm-zadarma/).
- [API oficial de Zadarma, integración WebRTC](https://zadarma.com/en/support/api/).
- [SDK examinado](https://my.zadarma.com/webphoneWebRTCWidget/v8/js/widget-api.min.js?sub_v=68), [JsSIP servido por Zadarma](https://my.zadarma.com/webphoneWebRTCWidget/v8/js/jssip.min.js?v=7).
- [WebRTC: acceso a dispositivos y restricciones](https://webrtc.org/getting-started/media-devices), [ejemplo oficial de selección](https://webrtc.github.io/samples/src/content/devices/input-output/).

## Conciliación con Hostinger

Los recursos públicos se descargaron únicamente para comparar; no se escribió en el servidor. El hash de `host.php` y su diferencia fueron proporcionados por el usuario desde SSH. Se reconstruyó esa diferencia y se comprobó el hash exacto antes de añadir el piloto.

| Archivo instalado | SHA-256 antes del piloto (LF) | Tratamiento |
|---|---|---|
| `public/javascript/telefonia_host.js` | `d313d210070a9c9bb8c6f5560916974a36512eecc7367d27ea317c2a70b4dfa8` | Sólo añadir el hook condicionado; runtime anterior idéntico |
| `app/views/telefonia/host.php` | `169372bcffee66f2a1f7adb77a6cfd1f8bf0fbe03c3df3f67dea602fa577cab6` | Preservar observador de Ventas, `salesSdkIncoming`, números y conexión; añadir carga condicionada |
| `public/javascript/telefonia_numeros.js` | `0a6777885ee77a84d44621f3a1b1e14f1fb33de05d186eb59c8ada7026fc46f3` | Incorporar al repositorio la copia pública exacta, sin modificarla |
| `public/javascript/telefonia_conexion.js` | `fc7417793f8307aa962a6d9165a1dc61fd9192e10f9ea95d89bcbbde7a26030d` | Incorporar al repositorio la copia pública exacta, sin modificarla |

Las dos últimas dependencias ya se cargan en producción pero faltaban en esta rama. El paquete **sólo las comprueba**, no las sustituye. `shell.php` tiene personalizaciones conocidas y no se modifica. Tampoco se sustituyen sus scripts, el cliente persistente ni sus estilos. Una discrepancia futura de hash bloquea el paquete antes de escribir.

## Funcionamiento

1. Sólo el host autorizado carga el gestor. Apagado o fuera del piloto, no se instala la envoltura, panel, lector de dispositivos ni temporizador del piloto.
2. Se enumeran entradas sin pedir permiso automáticamente. Nombres vacíos no se interpretan como avería. El botón voluntario **Autorizar y detectar** permite descubrir etiquetas; sus pistas temporales se detienen inmediatamente, sin monitor ni grabación. Está bloqueado durante llamadas.
3. En **Dispositivos de audio**, la selección manual se guarda localmente como `{version:1,deviceId}` bajo `impe:audio-input:v1:<usuario_id>:155`. No se guardan audio, etiquetas, teléfonos ni resultados. No se sincroniza entre computadoras/cuentas. Si localStorage falla, la elección sólo dura la sesión y se informa.
4. La preferencia disponible se añade únicamente a las nuevas capturas elegibles. Se conservan las demás restricciones del SDK sin mutarlas. Un `deviceId` físico explícito solicitado por el SDK se respeta, incluso si contradice al CRM; el panel mostrará la diferencia cuando exista sender verificable.
5. Sin preferencia, no se cambia un sender existente ni una entrada antes verificada. Se respeta el predeterminado identificable. La selección externa automática es deliberadamente conservadora: una sola entrada física identificable como externa, sin predeterminado identificable y sin decisión previa. Los alias `default/communications` no cuentan como otra entrada física. Varias entradas, nombres ocultos o dudas conservan la captura habitual y ofrecen selección manual. No hay detección infalible de USB ni acceso al predeterminado de Windows desde estas APIs.
6. Con una preferencia desaparecida se conserva la captura original. Si desaparece entre enumeración y apertura y el navegador produce un error de dispositivo/restricción, se reintenta **una vez** con las restricciones originales. Un error de permiso no dispara otro diálogo. No se ocultan errores propios de la captura original.
7. Guardar una preferencia o conectar/desconectar durante una llamada sólo actualiza el panel. No se llama a `replaceTrack`, `applyConstraints`, reinicio del SDK, cierre de sesión ni recreación de iframe. La selección hecha durante esa llamada se difiere hasta otra llamada. Una diadema retirada físicamente puede dejar sin audio al navegador; el CRM no puede impedirlo y no intenta repararlo manipulando la llamada.
8. La prueba de aplicación es una **única pista de audio live del sender de la sesión actual**, mediante `getSettings().deviceId`. `getConstraints()` sólo informa lo solicitado. Un stream de prueba, el cronómetro, una etiqueta o una captura exitosa no acreditan cambio en WebRTC. Si aún no existe sender o no expone ID, la preferencia queda pendiente. Si el SDK reutiliza una pista anterior, se informa; no se sustituye en caliente.

## Archivos

- `app/helpers/TelefoniaAudioDispositivosPilot.php`: bandera privada y aislamiento del piloto.
- `config/telefonia_audio.example.php`, `.gitignore`: ejemplo apagado y exclusión de configuración privada.
- `app/views/telefonia/host.php`: conciliación de la vista instalada y carga exclusiva del piloto.
- `public/javascript/telefonia_host.js`: hook opcional antes de inicializar el SDK; controles anteriores conservados.
- `public/javascript/telefonia_audio_dispositivos.js`: detección, preferencia, envoltura, lectura de sender y panel compacto en el shell propietario.
- `public/css/telefonia_audio_dispositivos.css`: estilos acotados, con límites de viewport y desplazamiento interno.
- `telefonia_numeros.js`, `telefonia_conexion.js`: copias exactas de las dependencias instaladas, sin cambios funcionales.
- `tools/publicar_microfonos_piloto.py`, `microfonos_piloto_release.json`: paquete selectivo opcional con check, respaldo y restore. No activa la bandera ni modifica SQL.
- Pruebas `telefonia_audio_*`, `publicacion_microfonos_piloto_test.py`.

## Pruebas automatizadas

Ejecutar desde la raíz del repositorio; requieren Node, PHP y Python. El ensayo de UI usa Playwright y Chrome local. Intercepta la red y simula dispositivos/senders: **no certifica audio ni hardware real**.

```text
node tests/telefonia_audio_dispositivos.cjs
node tests/telefonia_audio_dispositivos_browser.cjs
php tests/telefonia_audio_pilot.php
php tests/telefonia_audio_host_render.php
python3 tests/publicacion_microfonos_piloto_test.py
```

Resultados: PASS. Cubren aislamiento, preferencia antes de abrir, entradas correctas intactas, Realtek frente a preferencia USB, reconexión, dos USB, etiquetas ocultas, permiso revocado/restaurado, dispositivo desaparecido, fallback sin repetición de permiso, recarga/cuenta diferente, cambios activos sin capturas, envío real frente a prueba temporal, UI escritorio/móvil, hook real apagado y desmontaje del panel. El paquete se probó sólo en temporales con LF y CRLF, conflictos previos/posteriores, idempotencia y restore; configuración privada y demás archivos permanecen intactos.

También PASS en las regresiones existentes:

```text
node tests/telefonia_transferencia_operacion.cjs
node tests/telefonia_transferida_estado_sip_smoke.js
node tests/telefonia_analista_resultado_transferido_smoke.js
node tests/ventas_resultado_todos_cierres.cjs
node tests/recepcion_cierre_local_sdk.cjs
node tests/recepcion_rechazo_sdk.cjs
node tests/recepcion_cierre_local_browser.cjs
node tests/recepcion_rechazo_browser.cjs
php tests/telefonia_shell_render_smoke.php
```

Sintaxis de los JS/PHP añadidos y del host: OK. `git diff --check`: OK.

Dos pruebas heredadas fallan **también ejecutadas contra el commit de origen en una copia temporal**:

- `telefonia_shell_runtime_smoke.js`: el fixture no proporciona `IMPE_PHONE_NUMBERS`, dependencia de la versión del shell ya instalada.
- `telefonia_shell_smoke.php`: espera la antigua clave de configuración de autorrecepción en el shell.

No se modificaron esos controles del teléfono ni se cambió el producto para satisfacer expectativas antiguas. Estas pruebas heredadas siguen pendientes de actualizar; no se presenta la suite entera como aprobada.

## Prueba manual obligatoria y aceptación pendiente

Preparar primero un entorno de pruebas con HTTPS, la cuenta piloto y configuración autorizada de telefonía. No usar conexiones SIP reales adicionales de la misma extensión simultáneamente. Coordinar el piloto cuando no haya llamadas/transferencias activas y comprobar micrófono y grabación mediante los accesos existentes, sin exportar audio a esta herramienta.

| Escenario real | Comprobación y resultado a registrar |
|---|---|
| Diadema que ya funciona | Entrante y saliente sin preferencia nueva: sender y audio correctos, sin cambios |
| USB antes del inicio | Lista/permiso/preferencia recuperados, sin solicitud automática de permiso |
| Windows Logi / sender Realtek | Elegir Logi en CRM en reposo; siguiente llamada usa ID de Logi en sender; confirmar voz con interlocutor |
| Desconectar/reconectar la misma | Aviso, preferencia conservada; siguiente llamada con USB disponible; no se reinicia teléfono |
| Conectar otra / dos USB | Selector claro, ninguna imposición ambigua; escoger y comprobar nueva captura |
| Permiso revocado | Aviso correcto; sin bucle de permiso; autorizar de nuevo desde el sitio, detectar y probar |
| ID desaparecido/rotado | Telefonía original disponible o error nativo visible; reseleccionar sin error JS |
| Recarga / otra cuenta / extensión | Persistencia propia e inexistencia del piloto fuera de Prueba.llamadas/155/rol 3 |
| Cambio físico durante conversación | Sin interrupción provocada por CRM, sin replaceTrack; avisar si el navegador pierde audio |
| Silenciar/reactivar | Sender.enabled responde como antes; el panel refleja silencio, sin afectar audio recibido |
| Transferencia directa y consultada a analista | Contestar, conversar, transferir, devolver, colgar y siguiente llamada sin cambios |
| Saliente de Ventas | Modal/resultado, notas, historial, grabación autorizada, métricas y avisos existentes sin cambios |
| Navegar/segundo plano/múltiples pestañas | Un solo motor propietario y sesión; navegación no elimina host; panel único |
| Chrome actual / Chrome 86 / Mac real | Anotar versión, OS, permisos, IDs comparados y comportamiento; no inferir compatibilidad real de una simulación |

Registrar para cada caso fecha, navegador/versión, usuario/extensión, hardware y resultado. No anotar secretos ni grabaciones en Git. **Estos escenarios físicos no se han ejecutado desde este entorno. Los criterios de aceptación de audio real y piloto no están cerrados.** No prometer corrección del sonido hueco sin comparación independiente.

## Instalación opcional, activación y reversión

No ejecutar ahora en producción. Revisar el PR y validar el paquete en copia de pruebas. No usar `git pull` en `public_html`, copiar directorios completos ni sustituir versiones diferentes.

El paquete contiene sólo cinco archivos (helper, gestor, CSS, vista host y hook host). No instala la configuración privada. Sus hashes corresponden a las versiones conciliadas arriba. Las dependencias públicas y `config/private_config.php` deben coincidir; si no, **detener esa integración y comparar**, sin forzar el check.

Tras la revisión, con el paquete descargado fuera de public_html y sin llamadas activas, el operador puede comprobarlo sin escribir:

```bash
python3 publicar_microfonos_piloto.py --site "$HOME/domains/fundacionrededumex.org/public_html" --backup-root "$HOME/respaldos-impe-microfonos" --check
```

Sólo después de verificar el check y coordinar la ventana, `--apply` instala los cinco archivos y emite la ruta de respaldo. **Mantiene apagada la función si no existe configuración privada habilitada**. Copiar el ejemplo a `impe-private/telefonia_audio.local.php`, fuera de public_html, y cambiar únicamente `enabled` a `true` para el piloto acordado. No editar/reemplazar otras configuraciones privadas. El código restringe la activación al usuario/extensión/rol indicado; no hace falta conocer ni codificar su ID de base de datos.

Recargar el CRM de la cuenta piloto sólo en reposo, sin llamadas, transferencias ni gestión pendiente. Aparece el control **Dispositivos de audio**. Guardar una preferencia y efectuar la validación física de la tabla. No generalizar a otros usuarios hasta aprobarla. No requiere alterar permisos o asignaciones.

Para desactivar: cambiar `enabled` a `false` en esa configuración y recargar en reposo; el host nuevo usa la captura original. No cerrar una llamada para aplicar el flag. Para retirar además el código, con el mismo manifiesto y sin sesiones activas:

```bash
python3 publicar_microfonos_piloto.py --site "$HOME/domains/fundacionrededumex.org/public_html" --backup-root "$HOME/respaldos-impe-microfonos" --restore "/ruta/exacta/del/respaldo-emitido"
```

Restore valida que no haya cambios posteriores, recupera los archivos originales y retira sólo los archivos nuevos del paquete. No borra preferencias del navegador, configuraciones privadas, llamadas ni audio. El código sin piloto ignora esa preferencia. No se ha ejecutado este instalador contra Hostinger.
