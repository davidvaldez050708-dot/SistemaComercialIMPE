# Transición de datos del prospecto a resultado

## Causas

El script comercial de captura sólo ocultaba su botón cuando la fase dejaba de ser in-progress. El panel comercial es distinto al panel institucional que cierra el teléfono compartido, por lo que podía permanecer abierto después de colgar y cubrir el resultado. Una lectura HTTP iniciada durante la conversación también podía mostrarlo después del cierre. Al abrir el resultado se recuperaban nombre y correo de captura_prospecto, pero no sus notas.

## Cambios

- La captura comercial cierra y deshabilita sus campos al terminar la conversación. Conserva lo escrito como borrador y evita que una respuesta tardía vuelva a abrir el panel. El acceso «Registrar resultado» cierra la captura antes de navegar; la llamada y el SDK no se modifican.
- Un puente del mismo origen permite que el resultado recupere el borrador de esa referencia PBX y extensión. Las referencias tardías sólo se vinculan mediante la sesión SDK exacta conservada. No se busca por teléfono, hora ni por el último borrador disponible. Los borradores se mantienen por usuario y llamada en sessionStorage; la conservación entre pestañas sigue requiriendo «Guardar datos» en el servidor.
- Las notas locales más recientes o las ya guardadas se incorporan a Observaciones. Se conservan las observaciones existentes, se evita duplicarlas al reabrir y se respetan las ediciones posteriores del asesor. Si la combinación supera 1,000 caracteres, ambos textos siguen visibles y se requiere integrarlos manualmente antes de guardar; no se truncan.
- Un guardado en vuelo no elimina cambios escritos después del envío. El borrador de captura sólo se retira tras la confirmación del guardado del resultado de la misma PBX/extensión desde el iframe CRM autorizado.
- Los resultados definitivos permanecen de consulta. No se cambia el validador PBX, autorías, conteos, reportes, grabaciones, CSS, esquema ni datos de la base.

## Versiones instaladas confirmadas

| Archivo | SHA-256 previo |
| --- | --- |
| telefonia_ventas_prospecto.js | 82f0edaca1335463f0b70e566aed0d768aaa568cfb24a0feb27f939be327d251 |
| telefonia_ventas_resultados.js | 2c7e20c22f96fa28971ddf4e495f4226acc39af717aa0ffa6660ee8c29350f69 |
| telefonia_ventas_shell.js | 405bd799c886803b46e1e2361000f51b449d0f8bcae5f7b3cad9b38d2a69f889 |

El publicador instala únicamente estos tres scripts, conservando las personalizaciones de esas versiones exactas. El motor compartido telefonia_shell.js y el host no se sustituyen.

## Validación y publicación

- `tests/ventas_captura_transicion.cjs`: ejecuta los módulos completos en contextos de DOM/HTTP controlados. Comprueba cierre al colgar/registrar; lectura tardía; borradores y notas guardadas; fusión, edición y recarga sin duplicados; límite de longitud con bloqueo hasta edición; consulta de resultado definitivo; guardado en vuelo con cambios posteriores; referencia tardía por sesión exacta; extensión/ref ajenas; limpieza sólo tras guardado confirmado; y llamadas consecutivas al mismo teléfono sin mezcla.
- `tests/ventas_resultado_fecha_aviso.cjs`: regresión de fecha/hora, referencia y conservación del formulario ante rechazo del servidor.
- Node syntax check de los tres scripts.
- `tests/publicacion_ventas_captura_resultado_test.py`: tres archivos exactos, LF/CRLF, variantes desconocidas bloqueadas antes de escribir, check sin escrituras, respaldo, repetición y restauración protegida; un audio y el helper telefónico usados como testigos quedan intactos.

`tools/publicar_ventas_captura_resultado.py` usa el manifiesto `ventas_captura_resultado_release.json`. No ejecuta SQL. Tras instalar, el CRM debe recargarse cuando no haya una llamada en curso para cargar los tres scripts actualizados. La aplicación en producción se confirma con la salida del usuario.
