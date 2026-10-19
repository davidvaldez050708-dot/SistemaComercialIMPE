# Ventas · Mis llamadas: uniformidad y evidencia de audio

Base: `main` en `6eded94621a9a0b547628828cd70c6b3b3c0f715`, incluidos PR105 (conciliación horaria) y PR106 (CSS personalizado de Hostinger).
Aplicación: `3c180f9455ebe2b798fde73fe1541a8a6abc0deb`.

## Cambios y causas

- El observador comercial añadía un segundo aviso fijo inferior, aparte de la campana y sus toasts. Se elimina ese aviso. Se conservan el botón del teléfono, el registro desde el historial, el borrador y la recuperación de pendientes. Los avisos compartidos mantienen su intervalo de ocho minutos y lectura.
- Una rejilla de devoluciones de tres columnas distribuía cinco controles. Mis llamadas utiliza ahora los componentes de Seguimiento (`data-filter-field`, `module-search`, `linkage-tools-form`) y una composición responsive propia, sin duplicar su apariencia.
- Las tarjetas heredaban márgenes de párrafos y un segundo diseño de Ventas. Se utiliza la estructura compacta de Analista/Expedientes: identidad, fecha local, insignias, acciones y duración. Se elimina el borde amarillo; el intento sin PBX conserva una insignia neutra y no permite registrar resultados ni reproducir audio.
- El extractor comercial sólo aceptaba `NOTIFY_RECORD` con extensión. Según la [API oficial de Zadarma](https://zadarma.com/es/support/api/), `NOTIFY_END`/`NOTIFY_OUT_END` pueden aportar `internal`, `is_recorded` y `call_id_with_rec`; `NOTIFY_RECORD` puede carecer de extensión. Ahora se acepta una referencia del cierre de la extensión propia cuando está indicada como grabada o corroborada por el mismo aviso de grabación. Un aviso global aislado nunca atribuye audio a Ventas. Se excluyen referencias compartidas con otra extensión.
- La presentación distingue resultado pendiente, grabación pendiente de referencia, aviso sin tramo propio y ausencia de evidencia. Se mantienen el resultado humano requerido, la exclusión de buzones y el reproductor compartido por segmentos. El endpoint sigue solicitando exclusivamente el `call_id` del segmento autorizado, sin consultar el PBX completo.

No se modifican WebRTC, transferencias, permisos, credenciales, consultas de conciliación horaria, duración ni tablas. Tampoco se alteran los CSS compartidos de Analista, el teléfono o las grabaciones de otros perfiles.

## Verificación reproducible

Base de datos desechable, puerto explícito `IMPE_TEST_MYSQL_PORT`:

```bash
php tests/telefonia_ventas_audio_evidencia.php
php tests/telefonia_ventas_audio_db.php
php tests/telefonia_ventas_db.php
php tests/telefonia_ventas_gestion_db.php
php tests/telefonia_ventas_notificaciones_db.php
php tests/telefonia_atencion_db.php
php tests/telefonia_identidad_transferencia_db.php
```

Resultados locales: 19 contratos de audio, 10 de integración de audio, 31 comerciales, 28 de agenda/devoluciones, 48 de notificaciones/conciliación horaria, 144 de Analista y 12 de identidad, aprobados. La prueba de audio usa dos PBX consecutivos del mismo número, cierre propio, aviso global sin extensión, múltiples segmentos y un tramo de Recepción. Se verifica la ruta de diagnóstico dentro de una transacción de sólo lectura.

Playwright con PHP, Bootstrap, vistas, CSS y scripts reales; API/PBX simulados:

```bash
node tests/telefonia_ventas_historial_browser.cjs
node tests/telefonia_ventas_operacion_browser.cjs
node tests/telefonia_ventas_recepcion_browser.cjs
node tests/telefonia_ventas_gestion_browser.cjs
node tests/telefonia_modal_entrante_integracion_browser.cjs
node tests/telefonia_disponibilidad_browser.cjs
node tests/recordatorios_toasts_browser.cjs
```

Pasaron en Edge/Chromium: historial en 1440, 1024, 390 y 740 px; filtros y paginación conservados; sin desbordamiento horizontal; formulario accesible y sin teléfono superpuesto; borrador tras recarga; reproductor de dos segmentos propios; agenda; disponibilidad; conexión real por latido; once escenarios de cadencia/lectura/toasts. Las capturas de CI se adjuntan como `ventas-mis-llamadas-responsive`. Las pruebas simuladas no acreditan audio ni llamadas reales en Zadarma.

También pasaron las comprobaciones de sintaxis PHP/JS, guardias HTTP (11), runtime/navegación del shell, aviso sonoro, grabaciones salientes de Marketing (8) y contratos de propiedad/vista/navegación de Mis llamadas.

## Publicación selectiva, sin SQL

Cinco archivos de aplicación exclusivamente:

1. `app/services/TelefoniaVentasLlamadasService.php`
2. `app/views/telefonia/ventas_llamadas.php`
3. `public/css/telefonia_ventas_ui.css`
4. `public/javascript/telefonia_ventas_shell.js`
5. `tools/diagnosticar_audio_ventas.php` (nuevo; sólo CLI).

El publicador `tools/publicar_ventas_uniformidad_hostinger.sh` usa Git por HTTPS en una copia privada, revisión fija, huellas y parches contextuales. No usa Python ni Node en Hostinger. El CSS exclusivo de Ventas puede instalarse si falta; una variante existente desconocida de JS/CSS detiene la publicación. Las personalizaciones PHP fuera de los bloques modificados se conservan. Exige la revisión comprobada del marcador con conciliación horaria del PR105; si su variante no coincide, se detiene para revisión. No reemplaza ese marcador ni los CSS de teléfono/Analista.

Descargar el script desde el **commit de entrega indicado en el PR**, a una ubicación fuera de `public_html`, y comprobar primero:

```bash
bash "$HOME/publicar-ventas-uniformidad.sh" --check
```

Sólo después de `OK`, sin llamadas activas y habiendo revisado las pruebas:

```bash
bash "$HOME/publicar-ventas-uniformidad.sh" --apply
```

El respaldo queda en `$HOME/respaldos-crm/ventas-uniformidad-FECHA-PID`. Para revertir, usar la ruta exacta que imprime la ejecución:

```bash
bash "$HOME/publicar-ventas-uniformidad.sh" --restore "$HOME/respaldos-crm/RUTA_EXACTA_IMPRESA"
```

Ocho escenarios de publicación en réplicas: base GitHub, PHP personalizado, CRLF, CSS ausente, JS desconocido, CSS desconocido, conflicto PHP y dependencia antigua. Comprueban sitio intacto en `--check`/conflictos, conservación byte por byte del CSS personalizado real de Hostinger, idempotencia y rollback exacto. Un archivo editado después de instalar impide la restauración automática sobre esa edición. No se requiere migración.

## Evidencia pendiente en producción

No se accedió a los eventos ni a los archivos de audio de producción. No se declara recuperada ninguna grabación de las capturas. Después de la publicación autorizada, este diagnóstico sólo lee la atribución histórica y los eventos ya almacenados, sin conciliar, modificar registros ni consultar Zadarma:

```bash
cd "$HOME/domains/fundacionrededumex.org/public_html"
php tools/diagnosticar_audio_ventas.php --usuario=8 --limite=5
# O limitarlo al PBX de una llamada concreta:
php tools/diagnosticar_audio_ventas.php --usuario=8 --pbx=REFERENCIA_EXACTA
```

La salida excluye nombres, teléfonos, URLs, credenciales y payloads completos. Si no existen referencias propias verificables, conserva el estado pendiente real.

Pruebas finales con llamadas reales: saliente atendida y resultado humano con audio; transferencia 100→150 con segmentos separados; buzón sin reproducción; llamada sin referencia que conserve su intento y hora; guardado de resultado sin aviso duplicado; campana/toast compartido y móvil. Verificar también una llamada de Marketing y otra de Analista.
