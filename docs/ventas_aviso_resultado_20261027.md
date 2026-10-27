# Identificación y aviso al registrar resultados

## Diagnóstico confirmado

El usuario reportó el toast «El servicio telefónico aún no confirma que tu extensión atendió la llamada», y luego indicó que probablemente registraba la llamada de las 10:23. La consulta de sólo lectura del CRM identificó la llamada 77, extensión 150, con OUT_START y OUT_END `cancel`, cero segundos y ningún ANSWER. La consulta independiente de estadísticas PBX devolvió para esa misma referencia y extensión `no answer`, cero segundos e inicio 2026-10-27 10:23:34. Ambas fuentes coinciden para este registro. No hay evidencia para reclasificarlo como contacto telefónico confirmado.

La llamada 78 tenía START y ANSWER de la extensión 150 en la comprobación del CRM, y no apareció en la estadística consultada. No se infiere de ello su cierre, duración ni disponibilidad de audio. Esta entrega no declara resuelto ni modifica ese caso diferente.

## Corrección limitada

El error de presentación era llamar «aún no confirma» a un cierre ya recibido sin respuesta. El rechazo ahora identifica fecha/hora y estado de la llamada seleccionada, explica que no permite un resultado de conversación y pide comprobar la llamada atendida. Conserva exactamente la misma condición de validación del servidor.

El resumen del formulario añade la fecha y hora del registro seleccionado a su estado y extensión. Se formatea la fecha local SQL del historial como texto, sin convertir zonas horarias, cambiar los valores de captura ni usar HTML recibido del servidor. No se cambian las reglas de confirmación, llamadas pendientes de cierre, conteos, reportes, grabaciones, resultados anteriores o envío por WhatsApp.

Versiones confirmadas por el usuario:

- TelefoniaResultadoVentasService.php: `590b2bc062f0791610f601a363054a4a14a93252cd39a3b58d54f13ca5698656`.
- telefonia_ventas_resultados.js: `f650ef72ae01c4aae29f5d061e9bf91e93189dcd8b4ffdb647c2f5dde149f5ca`.

## Pruebas y publicación

- `tests/ventas_whatsapp_db.py`: 45 comprobaciones en base local desechable, incluidos cierre cancelado, mensaje con fecha/hora, guardado válido tras conversación, conexión requerida, propietarios, resultado definitivo, devolución y métricas sin duplicados.
- `tests/ventas_resultado_fecha_aviso.cjs`: ejecuta el módulo JavaScript real con DOM y HTTP controlados; verifica fecha/hora/estado y referencia de llamada. Ante 409 conserva formulario, observaciones y borrador, reanima el botón y no cierra el modal ni navega.
- `tests/publicacion_ventas_aviso_resultado_test.py`: sólo dos archivos, hashes exactos, LF/CRLF, check sin escritura, rechazo de variantes desconocidas, respaldo y restauración exacta; audio y helper de telefonía permanecen iguales.
- PHP lint y Node syntax check aprobados.

`publicar_ventas_aviso_resultado.py` y `ventas_aviso_resultado_release.json` instalan sólo el servicio y el JavaScript verificados, con respaldo privado y sin ejecutar SQL. La instalación en Hostinger se confirma con la salida del usuario.
