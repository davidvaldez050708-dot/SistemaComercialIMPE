# Primera etapa: resultado de llamada y envío de información

El usuario pidió ejecutar las mejoras por etapas y comprobar una antes de pasar a la siguiente. Esta entrega incluye exclusivamente el catálogo de resultados y el registro de información enviada. Mantiene el diseño del modal, su apertura al colgar, el guardado definitivo, los borradores y las observaciones adicionales.

## Formulario

Se presentan once resultados principales: buzón, número inexistente/incorrecto, no interesado, prospecto potencial, fuera del presupuesto, inscrito, no cumple requisitos, no solicitó información, siguiente apertura, no contestó y comunicación interrumpida. WhatsApp y SMS se capturan en otro selector, con ambos canales o sin envío. No son resultados adicionales ni generan llamadas extra.

El formulario pide motivo si no hubo WhatsApp. Esto deja constancia de excepciones y envíos pendientes sin afirmar un envío inexistente. El registro declara la acción del asesor: no envía mensajes ni comprueba entrega.

Comunicación interrumpida pide indicar si se alcanzó a conversar. Una declaración positiva requiere respuesta técnica propia y utiliza un código persistido distinto, visible como «Comunicación interrumpida · habló con una persona» en las consultas compartidas. El código histórico sin esa aclaración conserva su interpretación anterior. Las acciones de envío nunca acreditan contacto por sí mismas.

## Persistencia y compatibilidad

La tabla adicional `telefonia_ventas_resultado_detalles` guarda los campos V2 unidos al ID del resultado definitivo. Resultado y detalle se insertan en la misma transacción; un fallo revierte ambos. Una clave foránea evita detalles huérfanos si una limpieza autorizada elimina el resultado. No se concatenan acciones a las observaciones originales. La consulta del detalle comprueba resultado, propietario, extensión y PBX exactos.

El constructor de guardado prepara la tabla nueva antes de iniciar la transacción, igual que la estructura existente. No altera la tabla de resultados, no copia registros anteriores ni inventa envíos para ellos. Consultar un registro antiguo es sólo lectura. Los clientes anteriores conservan el contrato legado sin versión para compatibilidad durante la actualización; el formulario nuevo envía versión 2 y valida la lista nueva en servidor.

Las etiquetas históricas permanecen disponibles para Reportes, filtros y consultas. El formulario muestra una clasificación anterior únicamente al consultar un resultado ya guardado, en modo inmutable. La lista de captura no presenta las opciones anteriores. Los nuevos resultados con contacto mantienen compatible el cierre de devoluciones atendidas; no se cambia la transferencia ni el estado SIP.

La consulta propia muestra resultado y envío separados. La incorporación de acciones al diseño de reportes y supervisión corresponde a las siguientes etapas.

## Validación

- 133 comprobaciones SQL en MariaDB local desechable, sin acceso a configuración de producción: once resultados, acciones, ambos canales, motivos, contacto, corte, propietario, resultado definitivo, históricos, devolución atendida, métricas sin duplicados, rollback del segundo insert y limpieza sin huérfanos.
- Chrome aislado en escritorio 1366×900 y móvil 390×844: lista exacta, envío separado, un solo POST, consulta de resultado nuevo y antiguo, campos condicionales, validación y recuperación del borrador tras recargar.
- Regresiones del formulario inmediato con HTTP pendiente, llamadas consecutivas, respuestas tardías, cierre, guardado y notas.
- Publicador selectivo: hashes de los cinco archivos instalados confirmados por el usuario y un helper nuevo; prueba LF/CRLF, respaldo privado, rechazo de versiones distintas, idempotencia y restauración. Los scripts del host, shell y observador se verifican intactos en el sitio simulado.

El instalador no ejecuta SQL. Su restauración devuelve archivos anteriores; conserva los datos y la tabla nueva para no perder acciones ya registradas. Las pruebas no realizan llamadas ni reproducen grabaciones reales. La instalación y la prueba operativa en Hostinger quedan pendientes del usuario.

## Orden pendiente, tras comprobar esta etapa

1. Configurar jornada de cada asesor y metas 25/50; definir en un mismo cálculo contacto humano con audio propio, tanto entrante como saliente, con estados de evidencia pendiente.
2. Incorporar primera/última llamada por día, resultados y acciones separados, meta y métricas claras en Reportes, Desempeño y PDF; retirar «Conexiones confirmadas» como tarjeta principal.
3. Revisión de efectividad desde Supervisión: excluir/restablecer con motivo, auditoría, permisos por alcance y notificación al asesor. La selección y el cronómetro no prueban el contenido humano del audio.
