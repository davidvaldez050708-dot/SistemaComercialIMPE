# Corrección de la primera etapa: una lista, uno o dos resultados

El usuario sustituyó el diseño de dos campos por una sola lista con las trece opciones acordadas y máximo dos selecciones. Se retiran el selector separado de envío y la justificación obligatoria de no enviar WhatsApp. Un «No contestó» se guarda sin más campos de envío.

La lista desplegable usa casillas, paleta, tipografía y controles del sistema. WhatsApp/SMS pueden combinarse con un resultado o entre sí. Dos clasificaciones compatibles de conversación también se conservan, por ejemplo potencial y fuera del presupuesto. El servidor rechaza duplicados, más de dos opciones, códigos manipulados y contradicciones evidentes, como buzón con potencial. Un envío por sí solo no declara contacto humano.

La llamada mantiene una sola fila en `telefonia_ventas_resultados`. Su clasificación canónica conserva la compatibilidad de consultas y conteos existentes; ambas selecciones se guardan en `telefonia_ventas_resultado_selecciones`, con orden, unicidad y clave foránea. Resultado, detalle y selecciones se insertan en una única transacción. Un fallo al insertar la segunda selección revierte todo el resultado, sin borrar la llamada. El guardado sigue siendo definitivo.

Los contratos anteriores siguen siendo legibles y compatibles. Los registros V2 no se migran ni reescriben: un resultado anterior con WhatsApp y SMS conserva sus tres datos al consultarlo, aunque el formulario nuevo permita dos. Un motivo anterior se presenta sólo como dato histórico, nunca como requisito nuevo. Los borradores anteriores se recuperan; si traen tres selecciones, se conservan y se pide reducir a dos, sin recortar observaciones ni elegir por el asesor.

Se conserva la aclaración de contacto humano para una comunicación interrumpida cuando no se eligió otro resultado que ya lo declare. Las opciones de conversación requieren respuesta propia acreditada por PBX. La selección sigue siendo declaración del asesor; no acredita por sí sola grabación o efectividad.

Esta corrección no modifica host SDK, shell, observador, conexión, transferencias, apertura al colgar, grabaciones, permisos o metas. La incorporación de ambos resultados a la presentación de Supervisión, Reportes y PDF continúa pendiente de la siguiente etapa, conforme al orden solicitado por el usuario.

## Validación

- 192 comprobaciones SQL en base local desechable, incluyendo regresiones legado/V2 y selección V3, guardado sin motivo, máximo dos, combinaciones, propietario, contacto, rollback y limpieza sin huérfanos.
- Chrome aislado en 1366×900 y 390×844: trece casillas, límite, una selección sin explicación, dos seleccionadas, recuperación de borrador, consulta inmutable, lectura V2 de tres datos e histórico legado. Visibilidad del formulario con HTTP pendiente y conservación de observaciones.
- Regresiones de cierre, notas, PBX tardía y llamadas consecutivas.
- Publicador selectivo de cuatro archivos sobre las versiones del paquete anterior, con backup privado, rechazo de cambios desconocidos, LF/CRLF, idempotencia y restauración exacta. El instalador no ejecuta SQL. La tabla adicional se prepara desde el servicio de guardado sin alterar tablas anteriores; restaurar archivos no elimina datos registrados.

La implementación está preparada para comprobarse en Hostinger. No se han realizado llamadas ni consultado grabaciones reales en las pruebas.
