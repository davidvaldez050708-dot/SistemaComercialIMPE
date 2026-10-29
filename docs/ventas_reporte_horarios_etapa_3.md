# Reportes: buzones y horario de llamadas

Esta etapa sustituye «Conexiones confirmadas» por «Buzones de voz» en el reporte de actividad telefónica y su PDF. Cuenta llamadas cuyo resultado contiene `BUZON_VOZ`, una vez por identidad de llamada aunque también incluyan WhatsApp. El dato es la clasificación registrada por el asesor; no se deduce escuchando automáticamente el audio. Los hechos PBX, conversaciones, tiempos y criterios actuales del ranking se conservan.

Al consultar un asesor específico o el reporte personal se añade «Horario de llamadas»:

- Hoy y Ayer: tarjetas de primera y última llamada.
- Semana, mes y rangos personalizados: una fila por día, incluso en días vacíos.
- Las horas representan el inicio de la llamada registrado en el CRM, en México. No representan la hora de colgar.
- Se incluyen entrantes y salientes según el filtro activo; se respetan también los filtros de resultado. Intentos y transferencias sin referencia confirmada no desplazan el horario.
- Si sólo hay una llamada, ambas horas coinciden. Sin llamadas se presenta «—», conservando como dato válido una llamada real a las 00:00:00.
- El reporte de todo un equipo no presenta un horario individual, aunque actualmente sólo exista una persona disponible.

El repositorio existente ya entrega las fechas de llamada en México. No se cambia el reloj SQL ni se vuelve a restar un desfase al mostrar las horas. La misma agregación alimenta la vista y el PDF; no se consultan fuentes adicionales ni se recalculan extremos sobre la página de 25 filas del detalle.

## Alcance de instalación

El paquete modifica `VentasActividadMetricas.php`, `VentasActividadPdfView.php` y la vista `reportes/ventas_actividad.php`. Añade `ventas_reporte_horarios.css` con una regla de dos tarjetas y su adaptación a móvil. Usa los mismos componentes, tipografía, colores y tablas que Reportes/Desempeño.

Requiere las versiones instaladas de la etapa 2, commit `2b65829adcb30d4e4b92e2f8adc79a7457e76f9d`. El publicador valida los archivos y dependencias antes de escribir, conserva los saltos LF/CRLF y crea respaldo privado. No ejecuta SQL. La aplicación es repetible y admite restaurar con `--restore` si no hubo ediciones posteriores.

No se modifican permisos, autores, resultados, grabaciones, formulario de captura, SDK, cierre de llamadas, metas o jornadas. No se cambia la presentación de Desempeño en esta entrega.

## Validación

- `tests/ventas_consulta_doble_db.py`, ampliado con `ventas_reporte_horarios_casos.php`: captura y consulta de resultados, permisos/equipo, identidad, conteo único, fechas de medianoche, días vacíos, filtros reales del servicio y ausencia de escrituras.
- `tests/ventas_reporte_horarios_qa.py`: seis cortes de las vistas reales en Chrome, escritorio y móvil; PDF de un día y un rango con Dompdf y CSS del sistema. Renderizado con Poppler y revisión de todas las páginas.
- `tests/publicacion_ventas_reporte_horarios_test.py`: versiones conocidas, LF/CRLF, rechazo de ediciones desconocidas, check sin escrituras, respaldo privado, aplicación repetida y restauración exacta. Verifica la conservación del formulario, motores de telefonía, servicio de guardado y audio.

En Hostinger, seleccionar un asesor y consultar Hoy, Ayer y una semana. Comprobar las horas con el historial de llamadas del mismo corte y exportar el PDF. Probar un día vacío y un reporte de todo el equipo. La tarjeta de buzones debe coincidir con la categoría «Buzón de voz» dentro de los filtros seleccionados.
