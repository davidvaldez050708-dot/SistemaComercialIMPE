# Resultados de salientes de analistas según evidencia de telefonía

Antes, el formulario guardaba la interacción y después vinculaba la llamada.
La vinculación retiraba la verificación efectiva si no hubo respuesta, pero el
resultado comercial elegido ya permanecía en el reporte como contacto correcto.

Ahora ambos caminos de registro —panel de trabajo e interacción informativa de
la ruta avanzada— consultan la evidencia antes de insertar la interacción,
modificar contactos referidos o registrar verificaciones.

| Evidencia de cierre de la centralita | Resultados disponibles |
| --- | --- |
| Ocupada | Línea ocupada |
| Sin respuesta | Sin respuesta |
| Cancelada | Otro, sin verificación |
| Fallida o cierre sin conexión confirmada | Sin respuesta, fuera de servicio, número incorrecto u otro, según evidencia disponible |
| Conexión confirmada | Contacto y resultados de conversación; también buzón, locución/sin servicio, número incorrecto u otro |
| Identidad o cierre pendientes | Comprobar llamada antes de guardar |

ANSWERED acredita conexión, que puede ser un buzón o una locución; el analista
todavía debe indicar qué escuchó. Los resultados sin conversación no permiten
confirmar datos. La duración/status enviados desde el navegador no autorizan
contacto ni verificaciones.

La consulta valida el seguimiento propio activo, la extensión saliente autorizada,
el identificador PBX exacto, los eventos de esa extensión y el teléfono registrado
en el expediente. No busca una llamada anterior al mismo número como sustituta.
Si falta un identificador en el cliente instalado, sólo usa su checkpoint vigente
y su intervalo de marcado. La regla vuelve a consultarse en el POST al guardar.
Los registros manuales y otros canales conservan su tratamiento existente.

El formulario limita opciones, limpia/desactiva verificaciones incompatibles y
permite reintentar la comprobación. Una respuesta tardía de otra llamada no cambia
el formulario actual. El endpoint de consulta libera el bloqueo de sesión antes
de consultar telefonía y no guarda interacciones.

Validación realizada sin llamadas reales:

- PHP: escenarios de ocupada, no contestada, cancelada, fallida y conectada;
  resultados de conversación, buzón, datos confirmados, identificador/extensión/
  teléfono distintos, llamada todavía activa y duración de cliente manipulada.
- Chrome: opciones y bloqueo antes del guardado, cambios de persona a buzón,
  conservación de borradores, fallo de consulta, captura incompatible manipulada
  y respuestas fuera de orden.
- Publicación selectiva: cinco archivos, dos nuevos. Sintaxis PHP/JS; copias del
  repositorio y lectura instalada con LF/CRLF; conservación de personalizaciones;
  check sin escritura, aplicación repetida, respaldo/restauración y rechazo de
  conflicto antes de publicar cualquier archivo. Python compatible con 3.6.

Paquete: `tools/publicar_resultado_analista.py` y
`tools/resultado_analista_release.json`. No reemplazar archivos completos de
producción con la versión del repositorio. Las pruebas del servidor son de reglas;
la comprobación integral con una llamada real se realiza después de instalar.
