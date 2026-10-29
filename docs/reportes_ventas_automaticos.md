# Reportes de asesores con consulta automática

El controlador genera el reporte al entrar, sin exigir `generar=1`. El formulario consulta al cambiar asesor, periodo, tipo o resultado. Agrupa cambios rápidos durante 300 ms; los cambios de fechas esperan 600 ms y sólo se envían cuando ambas fechas son válidas. No se añade sondeo periódico.

Se retiran el botón habitual de Actualizar/Generar y el aviso de filtros pendientes. Sin JavaScript se mantiene un botón accesible de consulta y el servidor genera el reporte inicial igualmente.

Al seleccionar Personalizado por primera vez se abren ambos campos vacíos: las fechas calculadas del periodo anterior no se toman como un rango elegido por el usuario. No se envían rangos incompletos, invertidos, futuros o de más de 366 días inclusivos. La validación del servicio en el servidor permanece como autoridad. Las fechas personalizadas se deshabilitan al escoger un periodo normal.

El PDF anterior se oculta en cuanto cambian los controles. Al cargar el nuevo corte, el controlador conserva su mecanismo existente para generar el enlace de exportación con asesor, fechas, tipo y resultado aplicados. Si el usuario devuelve un filtro a su valor anterior antes de la consulta, se cancela el envío y se restaura ese enlace. Al regresar mediante Atrás se restablecen los controles al corte de la página restaurada.

La actualización usa el mismo formulario GET que antes se enviaba al pulsar Actualizar; conserva la navegación en el iframe del CRM. No se cambia la ventana del teléfono, el SDK, servicios de llamada, formularios de resultados, grabaciones o permisos. El filtro nuevo comienza en la primera página del detalle, sin arrastrar la paginación anterior.

## Versiones y publicación

Las versiones de Hostinger de `ventas_actividad.js` y `VentasActividadController.php` se identificaron mediante los hashes a8929301… y 65e704cb… facilitados por el usuario. La vista conserva lo instalado en la entrega 438dbc03. Las hojas de estilo, métricas, horarios de llamadas y PDF no se sustituyen.

El paquete sólo modifica esos tres archivos. Verifica las versiones y dependencias antes de escribir, admite LF/CRLF, crea respaldo privado y permite restaurar las versiones anteriores. No ejecuta SQL ni modifica registros.

## Validación

- `tests/reportes_ventas_automaticos_qa.py` usa el controlador, vista y JavaScript reales con servicios sintéticos y conexión de producción bloqueada. Comprueba generación inicial con o sin parámetro `generar`.
- `tests/reportes_ventas_automaticos_browser.cjs` comprueba en Chrome de escritorio y móvil: generación inicial sin bucles, ausencia del botón habitual, cambios agrupados, selección por asesor/periodo/tipo/resultado, rangos personalizados, cancelación de cambios, enlace PDF coherente, Atrás, ejecución dentro de iframe sin recargar el host y alternativa sin JavaScript.
- `tests/publicacion_reportes_ventas_automaticos_test.py` prueba instalación, repetición, rechazo de versiones desconocidas, check sin escrituras, respaldo y restauración exacta; conserva archivos del flujo telefónico y audio de prueba.

En Hostinger, entrar en Reportes sin pulsar ningún botón, seleccionar una asesora y cambiar Hoy/Ayer/semana/tipo/resultado. Para Personalizado completar ambas fechas y comprobar una sola actualización con el rango elegido. Exportar únicamente después de cargar ese corte.
