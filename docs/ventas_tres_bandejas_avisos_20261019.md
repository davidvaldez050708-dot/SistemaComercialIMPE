# Ventas: tres bandejas y avisos de resultados

Base de trabajo: main `d93de87d2887557a495584084b953c0717fc9b4a`. Aplicación final: `0e5e0fe916e7a1fb0fb1e598ac3bfa096214fb55`.

## Diseño preservado

La vista y el CSS compactos publicados en Hostinger difieren de los archivos de main. El PR109 ya conserva esas variantes en `tools/ventas_pr108_hostinger/before` y `after`. Esta entrega parte de ellas, conserva íntegramente su CSS y mantiene las tarjetas de Historial: medidas, bordes, colores, Manrope, botones, duración y estado de audio.

Se retira únicamente el botón Filtrar de Historial. Los tres formularios aplican los cambios mediante el GET habitual del CRM: búsqueda con 450 ms de espera, selectores/fechas al cambiar, sin peticiones duplicadas, con recuperación del foco y posición de escritura. La búsqueda respeta composición de texto. Cambiar un filtro elimina la página anterior y las referencias que abrían un resultado.

Se corrigió un fallo funcional del script anterior: el input oculto `name=action` del router eclipsa `form.action` y podía producir una ruta `[object HTMLInputElement]`. Ahora se utiliza `getAttribute('action')` y se valida el rango de fechas antes de navegar.

Devolver y Programadas reutilizan campos, buscador, selectores nativos, tarjetas, filas de motivo/detalle, estados, contadores y estados vacíos de Historial/Seguimiento. Las tres vistas comparten una paginación accesible, con filtros conservados y controles deshabilitados cuando corresponde. Programadas distingue contactos por atender y próximos, sin fabricar resultados.

Los helpers de presentación de audio y el observador sin aviso flotante duplicado se alinean con la versión PR107 ya publicada; no se regresan a la versión anterior de main. No cambian las reglas de propiedad, WebRTC, transferencias, grabaciones ni conciliación horaria.

## Traza funcional del aviso pendiente

1. Los eventos PBX y la atribución histórica determinan el tramo del asesor, su cierre y conexión. Un intento sólo observado en navegador no acredita una llamada confirmada.
2. `TelefoniaNotificacionVentasService::pendientes()` consultaba `listar()`, que primero ejecuta toda la conciliación auxiliar. Un fallo de esa conciliación abortaba la generación de avisos, incluso si la llamada ya estaba atribuida y confirmada en las tablas. `ReminderController` capturaba la excepción y continuaba sin avisos telefónicos. Se reproduce ese caso con la tabla auxiliar del marcador temporalmente fuera de servicio, exclusivamente en una base desechable.
3. Ahora, si falla el conciliador, se consultan las llamadas persistidas por la misma ruta de permisos, propietario, PBX y resultados, sin ejecutar la conciliación. Si falta el acceso o el almacenamiento obligatorio, sigue rechazándose la consulta; no se inventan datos.
4. Se genera la llave estable `ventas:llamada:ID:resultado`; `INSERT IGNORE` y su índice único evitan duplicados. Una conversación conectada se identifica como `resultado_pendiente`, no como llamada perdida. Leer el aviso no se revierte en el siguiente polling.
5. `ReminderController::pendientes()` devuelve el aviso en `recordatorios`, con propietario, fecha y enlace comercial. El orden de prioridades compartido no cambia.
6. `recordatorios.js` muestra la campana y utiliza el toast institucional superior dentro del intervalo compartido de ocho minutos. No se añade otro toast ni otro polling.
7. El observador de Ventas avisa a la vista del CRM una sola vez cuando la API confirma el cierre y, nuevamente, al guardar el resultado. Esto actualiza la campana sin esperar el siguiente minuto. No fuerza un toast inmediato ni notifica un estado activo.
8. Una vez registrado el resultado, la llave deja de estar pendiente y el mecanismo existente marca el aviso como leído; campana y cola del toast lo retiran.

La causa reproducida y el recorrido completo están verificados en la base de pruebas y en HTTP/navegador. No se consultaron registros ni logs de producción, por lo que no se atribuye una excepción concreta al servidor de Hostinger sin su evidencia.

## Validación

- Playwright con los PHP, CSS, Bootstrap y scripts reales: tres vistas, cuatro resoluciones (1440, 1024, 390 y 740), filtros automáticos, recuperación del foco, fechas, paginación y estados vacíos. Compara el estilo de las tarjetas de Historial contra el snapshot de Hostinger y comprueba que su CSS permanece íntegro.
- Prueba de integración `ventas_avisos_integracion_browser.cjs`: eventos y datos en una base aislada → fallo auxiliar → generación y persistencia → controlador HTTP real → campana → toast tras ocho minutos → guardado real del resultado → retirada del aviso. Comprueba idempotencia, ausencia de avisos ajenos y exclusión de llamadas sin cierre.
- 48 comprobaciones existentes de notificaciones/conciliación, 31 comerciales, 28 de agenda/devoluciones, 19 contratos de audio y 10 de integración de audio.
- Regresiones de Analista (144 reglas de datos y modales), identidad de transferencia (12), disponibilidad, topbar, formularios y once escenarios compartidos de toasts. La prueba de interrupción del modal ahora espera explícitamente que termine la animación y se retire el backdrop antes de comprobar su ausencia; no se modifica el runtime del Analista.
- Nueve escenarios del publicador: main, Hostinger antes/después del PR109, PHP personalizado compatible, CRLF, JS/CSS desconocidos, Historial desconocido y edición posterior a la instalación. Verifican check intacto, huellas, publicación selectiva, idempotencia y restauración exacta. Los CSS personalizados del teléfono y del Analista quedan intactos.

Comandos de pruebas principales:

```bash
node tests/ventas_bandejas_browser.cjs
node tests/ventas_avisos_integracion_browser.cjs
php tests/telefonia_ventas_notificaciones_db.php
python3 tests/publicacion_ventas_bandejas_test.py
```

Las pruebas con DB requieren `IMPE_TEST_MYSQL_PORT` de una instancia aislada; el test HTTP sólo utiliza una base con prefijo `impe_bandejas_e2e_`. Ninguna prueba utiliza cuentas ni llamadas reales de Zadarma.

## Publicación y restauración

Nueve archivos de runtime:

- `app/services/TelefoniaVentasLlamadasService.php`
- `app/services/TelefoniaNotificacionVentasService.php`
- `app/views/telefonia/ventas_llamadas.php`
- `app/views/telefonia/ventas_devoluciones.php`
- `app/views/telefonia/ventas_programadas.php`
- `app/views/telefonia/ventas_paginacion.php` (nuevo)
- `public/css/telefonia_ventas_ui.css`
- `public/javascript/telefonia_ventas_auto_filtros.js`
- `public/javascript/telefonia_ventas_shell.js`

Descargar `tools/publicar_ventas_bandejas_hostinger.sh` desde el commit de entrega fijo indicado en el PR, guardándolo como `$HOME/publicar-ventas-bandejas.sh`. No usar git pull sobre `public_html`.

```bash
bash "$HOME/publicar-ventas-bandejas.sh" --check
```

El script usa Git por HTTPS en una copia privada y prepara los archivos sin ejecutar el CRM ni SQL. Reconoce las variantes revisadas de Hostinger y conserva personalizaciones PHP compatibles. Un Historial, JS o CSS desconocido detiene la instalación: no se sobrescribe. También exige la versión revisada del marcador para conservar la conciliación horaria. Instala primero el parcial nuevo para evitar includes incompletos.

Después de revisar el PR y obtener `OK` en el check, sin llamadas activas:

```bash
bash "$HOME/publicar-ventas-bandejas.sh" --apply
```

El respaldo se crea fuera de `public_html` en `$HOME/respaldos-crm/ventas-bandejas-FECHA-PID`. Usar la ruta exacta impresa para restaurar:

```bash
bash "$HOME/publicar-ventas-bandejas.sh" --restore "RUTA_EXACTA_DEL_RESPALDO"
```

Si un archivo fue editado después de la publicación, el restore se detiene para no perder esa edición. No hay migración nueva. El publicador requiere Bash, Git y PHP; no depende de Python ni Node en Hostinger.

No se publicó ni se modificó la BD de producción. La comprobación final en Hostinger debe incluir una saliente contestada sin resultado, su aviso en campana y toast respetando la cadencia, guardado del resultado y retirada del aviso; además de las tres bandejas en móvil y una llamada de regresión de Marketing y Analista.
