# Recordatorios de actividades comerciales V3

Base instalada: `a04beeb6d9b81f8e868ea8fdf5b40e77825c41b5`.

Esta fase conecta las actividades comerciales vigentes con la campana existente.
No incorpora aún avisos nuevos de reparto de prospectos ni notificaciones generales
de cada cambio de etapa. No envía llamadas, mensajes, WhatsApp o correos.

## Tiempos conservados

- Anticipaciones: **3 horas, 1 hora y 10 minutos**; al llegar la fecha, VENCIDA.
  Se reutiliza `resolverTipoAvisoRecordatorio` del módulo de Analista.
- Consulta de campana cada **60 segundos**, con actualización adicional al guardar.
- Cola institucional compartida: **un turno cada ocho minutos por usuario**.
- Cada toast permanece **siete segundos**, como los recordatorios actuales.

Las anticipaciones son ventanas de elegibilidad dentro de esa cola; no se dispara
una ráfaga ni se promete una alerta al segundo exacto. Al ingresar tarde se usa la
ventana actual, sin emitir las anticipaciones anteriores. Las fechas se muestran
en México aunque el navegador utilice otra zona horaria.

## Ciclo operativo

La fuente es el último evento de `prospecto_embudo_actividad_v3` de cada asignación
vigente del asesor. La campana muestra nombre, tipo de tarea, detalle original y
fecha completa. Incluye las actividades futuras y vencidas sin limitarse a las
veinte tarjetas del tablero ni a los doce avisos del listado anterior. Conserva
el contenedor con desplazamiento y los estilos institucionales.

El ciclo de anticipación se identifica con la programación/reprogramación real,
no con una nota posterior. Registrar una gestión conserva la tarea y no reinicia
sus avisos. Reprogramar invalida la fecha y el ciclo anteriores. Completar elimina
el pendiente de la campana, sin borrar su historial. Leer, cerrar o dejar caducar
un toast no completa la actividad. La actividad antigua sin tipo conserva su
motivo de programación como detalle y se presenta como Gestión programada.

Las asignaciones en Recuperación, Descartados, finalizadas, no vigentes o con
restricción No contactar no generan estos recordatorios operativos. El mero
vencimiento no cambia etapas ni ejecuta acciones comerciales.

La agenda telefónica deja de duplicar una próxima llamada sólo cuando hay una
vinculación PBX exacta con una actividad V3 programada después de esa vinculación.
No se compara únicamente el teléfono. Se conservan la agenda personal y las
llamadas sin ese vínculo. Completar o reprogramar la actividad comercial no deja
vigente su recordatorio telefónico anterior.

El filtro Sólo vencidos utiliza la fecha V3 cuando existe un evento V3; una agenda
anterior ya vencida no convierte en vencida una actividad futura o completada.
Las fichas sin actividad V3 mantienen la interpretación de agenda anterior.

## Actualización y aislamiento

Asesor con `ventas.embudo.ver` puede consultar sus actividades sin necesitar permiso
de Telefonía. No puede consultar las de otro asesor. Los avisos de Analista, Agenda,
Marketing, aliados y Administración conservan sus fuentes, permisos y tiempos.

Al guardar se solicita actualizar la campana del documento, del contenedor padre
cuando corresponde y de otras pestañas del mismo navegador. Una respuesta que
empezó antes de un cambio no puede revivir el pendiente anterior. Si falla la
fuente comercial, se conserva su última lectura válida, se informa del fallo y
siguen actualizándose los avisos disponibles de las otras fuentes. No se presentan
toast comerciales obsoletos mientras falta confirmar su estado.

Cada anticipación comercial se deduplica por usuario, actividad, ciclo y ventana;
los vencidos conservan la repetición de ocho minutos. Web Locks y almacenamiento
local coordinan las pestañas de un navegador. Si el almacenamiento está bloqueado,
se conserva memoria durante la página; no se promete deduplicación entre dispositivos
o navegadores diferentes. La tarea permanece en la base y se recupera al volver a
ingresar. No hay notificaciones del sistema operativo con el CRM cerrado.

## Validación

- `comercial_recordatorios_v3_db.php`: MariaDB aislada, 100 asignaciones sintéticas,
  3H/1H/10M/vencimiento, más de 80 pendientes, identidad y permisos, consultas sin
  escrituras, nota, reprogramación, cumplimiento, recuperación, descarte, vínculo PBX
  sin duplicados y respuesta del controlador real sin permiso de Telefonía.
- `comercial_recordatorios_v3_browser.cjs`: once escenarios con reloj controlado:
  duración, anticipación única, vencidos, nota, reprogramación, cumplimiento,
  respuestas atrasadas, fallos parciales, pestaña oculta, almacenamiento bloqueado,
  dos pestañas simultáneas, 85 tareas y zona de navegador distinta de México.
- Regresiones: once escenarios de `recordatorios_toasts_browser.cjs`, orden de
  recordatorios, 50 comprobaciones de Telefonía, plantillas de asesor/supervisión,
  filtro Sólo vencidos y actualización parcial del tablero.
- Publicador: hashes, sintaxis, bloqueo de personalizaciones, respaldo, aplicación
  idempotente, rollback y recuperación ante fallo parcial de archivos.

## Publicación y reversión

`tools/publicar_recordatorios_comerciales_v3_hostinger.py` sólo publica los siete
archivos especificados. CHECK consulta metadatos y verifica las bases exactas.
APPLY respalda el código antes de sustituir archivos. No hay migración SQL ni
modificación de actividades, resultados PBX, asignaciones o permisos durante la
publicación. Se conserva la variante protegida de Telefonía revisada en Hostinger.

```bash
SITIO="$HOME/domains/fundacionrededumex.org/public_html"
PUBLICADOR="$ENTREGA/tools/publicar_recordatorios_comerciales_v3_hostinger.py"
python3 "$PUBLICADOR" --site "$SITIO" --stage "$ENTREGA" --check &&
python3 "$PUBLICADOR" --site "$SITIO" --stage "$ENTREGA" \
  --backup-root "$HOME/respaldos-recordatorios-comerciales-v3" \
  --apply --confirm PUBLICAR_SEGUIMIENTO_ACCIONES_V3_IMPE &&
python3 "$PUBLICADOR" --site "$SITIO" --stage "$ENTREGA" --check
```

Si se detecta personalización desconocida, no forzar hashes ni copiar por encima.
Para revertir, utilizar el mismo paquete y el ROLLBACK_BACKUP exacto:

```bash
python3 "$PUBLICADOR" --site "$SITIO" --backup-dir "$RESPALDO_EXACTO" \
  --rollback --confirm REVERTIR_SEGUIMIENTO_ACCIONES_V3_IMPE
```

Después de aplicar, Ctrl+F5 una vez. Primera prueba con una tarjeta ficticia:
programar una actividad a una hora futura y abrir la campana. Deben aparecer tipo,
detalle y horario, sin recargar. Reprogramar debe sustituir ese pendiente y completar
debe retirarlo. El toast sigue su turno de ocho minutos: no confundir su espera con
una falla de guardado. Ninguna prueba requiere llamar ni enviar mensajes reales.
