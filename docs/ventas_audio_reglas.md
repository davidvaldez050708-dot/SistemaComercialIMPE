# Reproducción de grabaciones según el resultado guardado

Sustituye la regla demasiado amplia del paquete anterior por la política indicada por el usuario:

| Registro | Reproducción |
| --- | --- |
| Sin resultado guardado | Bloqueada; etiqueta «Pendiente de resultado» |
| No contestó, buzón u otro resultado sin contacto | Bloqueada |
| No contestó o buzón, combinado con WhatsApp/SMS | Bloqueada; el resultado sin contacto prevalece |
| WhatsApp/SMS solos, o ambos canales | Consulta permitida únicamente con estado técnico válido y archivo verificado |
| Resultado de conversación admitido | Consulta permitida únicamente con estado técnico válido y archivo verificado |

Una conexión técnica y 33 segundos no prueban conversación humana. WhatsApp/SMS solos continúan sin acreditar contacto humano o efectividad. No se modifica el estado PBX «Conectada» ni se reinterpretan llamadas históricas. Los resultados, notas y archivos de audio permanecen intactos.

## Implementación

`audioConsultable()` exige un resultado persistido, aplica prioridad a las selecciones sin contacto y conserva los requisitos de referencia, extensión, respuesta y cierre/transferencia. El repositorio entrega al helper las selecciones y el indicador de resultado guardado. La reproducción de Supervisión y la API personal usan la misma regla; no basta con ocultar botones. La vista de Supervisión distingue un resultado pendiente de una reproducción no habilitada.

El paquete sustituye cuatro archivos sobre la entrega `a75e4626` y verifica como dependencias las versiones ya instaladas del historial personal y su API, sin volver a modificarlas. No cambia JavaScript del teléfono, formulario de registro, permisos, métricas, metas, datos SQL o grabaciones. Mantiene el diseño del modal y las tarjetas.

## Pruebas

493 comprobaciones DB en MariaDB desechable. Incluyen llamadas con respuesta PBX, duración y referencia de audio, pero clasificadas como No contestó, No contestó + WhatsApp, buzón, buzón + WhatsApp y pendientes de resultado. Confirman bloqueo tanto en las dos vistas PHP reales como en ambas rutas del servidor, antes de consultar al proveedor.

Se mantienen pruebas positivas para WhatsApp/SMS solos y conversación con WhatsApp; prueba de transición al guardar el resultado, prioridad de negativos, alcance del equipo, propietario, extensión, permisos revocados, streaming y Range. Los conteos y los resultados persistidos permanecen iguales. El publicador comprueba variantes desconocidas, dependencias, instalación idempotente, respaldo y restauración exacta.
