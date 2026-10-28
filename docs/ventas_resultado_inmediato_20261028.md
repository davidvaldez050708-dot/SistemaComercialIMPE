# Resultado comercial inmediato al terminar una llamada

El asesor abre directamente el formulario habitual al finalizar su atención telefónica. El formulario se prepara en la página del CRM antes de colgar; la transición no espera una navegación, el siguiente intervalo del observador ni una respuesta HTTP. No se intercala el resumen «Llamada finalizada».

## Comportamiento

- Contestada, ocupada, cancelada, sin respuesta y fallida admiten captura humana después del cierre de la llamada propia.
- Si la referencia PBX o el cierre todavía no están confirmados, el formulario permite escribir y conserva el borrador. Guardar permanece deshabilitado hasta la validación del servidor.
- Una PBX tardía se vincula sólo a la misma sesión local. No se busca una llamada por número, cercanía temporal o último resultado.
- «Cancelada por el asesor» exige un motivo de al menos tres caracteres. «Buzón de voz» y «Mensaje de operadora o grabadora» siguen siendo declaraciones del asesor, aunque el estado técnico sea cancelada o contestada.
- Ninguno de esos resultados sin contacto humano cuenta como conversación. Las opciones que sí acreditan contacto requieren conexión confirmada; esta comprobación se mantiene también en el servidor.
- La ficha de datos del prospecto se cierra al pasar al resultado. Sus notas se recuperan mediante el mecanismo existente de la misma llamada, sin reemplazar la captura del asesor ni mezclar atenciones.
- Cerrar o interrumpir conserva el borrador. Un resultado SQL ya guardado no se vuelve a solicitar. Una respuesta tardía de una atención anterior no cierra ni retira el formulario de la siguiente.
- El historial conserva su modal existente. El footer no lo duplica; sólo prepara uno para el asesor autorizado en las otras páginas.

## Archivos y publicación

La entrega selectiva contiene dos servicios, las dos vistas existentes y tres scripts de interfaz. Los siete hashes anteriores se contrastaron con las salidas de Hostinger proporcionadas por el usuario. El manifiesto conserva las variantes instaladas, incluida la recuperación de conexión antigua en Opera y las correcciones de zona horaria/propietario de la extensión.

El controlador personalizado de Control de llamadas no se sustituye. Tampoco se modifican el host SDK, las transferencias, los reproductores, las referencias de audio, la configuración telefónica ni el reloj SQL. El instalador no ejecuta SQL ni migra resultados anteriores.

`tools/publicar_ventas_resultado_inmediato.py` verifica todos los archivos antes de escribir, rechaza variantes desconocidas, respeta LF/CRLF, crea respaldo privado fuera de public_html y permite restauración exacta. Aplicar con las pestañas del CRM cerradas y sin llamadas activas. El estado de instalación se verifica con `--check`; esta documentación no acredita una instalación en producción.

## Validación ejecutada

- `tests/ventas_todos_cierres_db.py`: 79 comprobaciones reales en una base MariaDB local desechable, conexión explícita y configuración de producción inaccesible. Incluye autorización de extensión, resultados definitivos, clasificación, motivo, devoluciones, reportes y ausencia de inflación de conversaciones.
- `tests/ventas_resultado_todos_cierres.cjs`: observador y módulo reales, formulario visible antes de resolver HTTP, PBX tardía, retiro tras guardar, no reapertura histórica y respuesta de guardado de una llamada anterior durante otra atención.
- `tests/ventas_captura_transicion.cjs` y `tests/ventas_resultado_fecha_aviso.cjs`: regresiones de notas, borradores, fechas y errores sin perder captura.
- `tests/ventas_resultado_preparado_php.py`: vistas reales, un solo formulario, autorización y agenda exclusivamente propia. Con `IMPE_BROWSER_TEST=1`, Chrome aislado en 1366×900 y 390×844 comprueba visibilidad con HTTP pendiente, diseño dentro de pantalla, validación y conservación de notas, incluidos cierres cancelados por un consumidor del modal.
- `tests/publicacion_ventas_resultado_inmediato_test.py`: publicación simulada LF/CRLF, rechazo de personalizaciones desconocidas, comprobación sin escrituras, respaldo, idempotencia, rechazo de restauración sobre cambios posteriores y restauración exacta. Audio de prueba y helper horario permanecen idénticos.

Las pruebas no realizan llamadas ni consultan grabaciones de producción. La prueba telefónica final en Hostinger corresponde al usuario después de aplicar el paquete.
