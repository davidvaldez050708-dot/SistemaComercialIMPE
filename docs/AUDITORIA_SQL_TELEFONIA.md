# Carga SQL de telefonía después del incidente 403

Hostinger atribuyó el bloqueo del 11 de noviembre de 2026, 17:01–17:03 CST, a su protección automática por carga sostenida de MySQL. El umbral interno sigue sin conocerse. El publicador continúa bloqueado.

## Inventario inicial

| Ruta | Trabajo por solicitud |
| --- | --- |
| Disponibilidad POST POLL, libre, sin solicitud ni devoluciones | Dentro del servicio: 1 lectura de extensión, 1 UPSERT de presencia, 1 UPDATE de expiración y 2 SELECT de detección en 0899e689. El cambio deja 1 SELECT de detección. La autorización del controlador queda fuera de este subtotal. |
| Disponibilidad ocupada o con solicitudes | Ocupado añade 1 UPDATE de reservas. Una solicitud encontrada añade la consulta de detalle. Reconciliar estados intermedios añade consultas y puede escribir. |
| Devoluciones en POLL | El cliente solicita el conteo cada 15 s por documento. Se consultan hasta 100 operaciones para conciliar y después se obtiene la lista. El costo depende de su historial, aunque el número de pendientes sea cero. |
| zadarma_entrada_activa | Revalida sesión y permisos, resuelve la extensión y consulta eventos PBX por referencia o ventana. Las lecturas de eventos dependen de la rama. No se introduce caché de permisos. |
| detalleVenta | Ejecuta sincronización antes de consultar la ficha. Es un recorrido de costo variable que puede escribir; hace falta trazar sus disparadores y medirlo por separado. |
| Campana de Marketing y recordatorios | Sincronizan devoluciones, llamadas perdidas y transferencias. Los bucles por llamada u operación agregan consultas. La campana de Marketing ejecutaba además DDL al construir el servicio. |

El bloqueo de solicitud en vuelo y el intervalo de tres segundos son por documento. Falta medir en navegador cuántos documentos emiten realmente los sondeos al abrir varias pestañas y rotar el propietario del teléfono. No se cambió el intervalo ni la ventana de 15 s de presencia.

## Cambios y comprobación local

- La detección de solicitudes usa un SELECT con NOT EXISTS para respetar las reservas vigentes. En MariaDB local con filas sintéticas el contador de sesión midió 2 SELECT en un ciclo libre, frente a 3 por inspección del código anterior. En total son 4 sentencias del servicio frente a 5, con las mismas 2 sentencias que pueden escribir. A 30 clientes con un ciclo cada 3 s, la reducción estimada para este caso es 10 consultas por segundo. La estimación excluye permisos, devoluciones, entradas y notificaciones.
- La migración 2026_10_18_transferencia_operaciones_notificaciones.sql ya declara la tabla. El constructor normal de la campana deja de ejecutar CREATE TABLE; la preparación explícita se conserva. La lista reutiliza la extensión y evita repetir la lectura de rol. El UPDATE de devoluciones sólo toca avisos aún no leídos que ya fueron atendidos. Se debe confirmar el esquema real en Hostinger antes de autorizar instalación.
- Se propagan los fallos del servicio de campana. Las dos rutas que la consumen devuelven 503 para fallos temporales reconocidos y 500 para otros errores. Esta revisión no demuestra que todos los demás servicios aguas abajo propaguen sus fallos.
- Pasaron la prueba de contador local, la prioridad de una reserva sobre una solicitud pendiente y las 50 permutaciones de orden de la campana. No son pruebas de saturación ni de LSAPI.

## Ensayo pendiente antes de liberar

Ejecutar tests/mysql_lsapi.py en Linux con un vhost OpenLiteSpeed aislado, PHP web 8.2, mysqlnd y MariaDB desechable. El arnés actual comprueba la identidad del laboratorio; sus perfiles no constituyen todavía la prueba de una hora requerida. Ampliar el escenario para repetir con 10 y 30 trabajadores, pestañas múltiples y diez rotaciones, mantener sondeos y avisos al menos una hora por ejecución, y provocar reciclaje de workers durante la carga. Incluir flujos sintéticos de llamadas, transferencias, webhooks repetidos y acciones concurrentes.

Comparar el mismo escenario contra 0899e689. Registrar sentencias y escrituras por segundo, conteo y latencia por ruta, conexiones activas y Sleep, aperturas por segundo, errores 2002/1203/1226, HTTP 403/500/503, tiempo hasta timbrado y resultados correctos de llamadas y notificaciones. El contador global por sí solo no separa tráfico de fondo; usar trazas del laboratorio por ruta.

Las rotaciones previas llegaron a 46, 58, 69 y 75 conexiones. No se ha demostrado que el pool llegue a una meseta por debajo del límite. Medir por PID y credencial con reciclaje controlado antes de proponer una vida útil o una política de persistencia. Para reducir escrituras de presencia, medir primero cuánto margen queda en la ventana de 15 s con latencia y pestañas en segundo plano, y conservar la publicación inmediata cuando cambia el estado.

Este entorno Windows no tiene un vhost LSAPI disponible. No se ejecutó la matriz de larga duración ni carga contra Hostinger. Hostinger debe confirmar los límites relevantes, el alcance temporal de la protección y qué métricas puede facilitar para correlacionar el incidente. La reducción observada en un ciclo no permite afirmar estabilidad para 30 trabajadores.

## Integración de la corrección de presencia

La corrección de 32d825a conserva el sondeo de tres segundos y confirma el heartbeat en la sesión PHP sólo después de éxito. El sello toma la hora anterior al UPSERT para no extender la vigencia aparente cuando la consulta tarda. Un cambio ocupado/disponible actualiza en el siguiente sondeo y la acción explícita invalida la marca. La frecuencia de seis segundos deja margen frente a la ventana de 15 s durante sondeos regulares; retrasos del navegador, interrupciones y sesiones concurrentes independientes requieren validación HTTP.

La prueba local ejecutó dos pestañas por sesión simulada, diez rotaciones, cuatro ciclos por rotación y cambios de disponibilidad con 10 y 30 usuarios sintéticos. Los UPSERT medidos bajaron de 800 a 300 y de 2400 a 900 (62.5% en este escenario). Los SELECT quedaron en 1600 y 4800 respectivamente frente al mismo código con heartbeat incondicional. Este ensayo serial usa una sola conexión y un reloj simulado. No mide conexiones simultáneas, latencia web ni códigos HTTP.

La campana administrativa reutiliza el handle de la petición para sincronizar convocatorias y después consultar avisos. El sincronizador termina su transacción antes de las lecturas posteriores. Esto elimina una apertura de handle independiente en ese recorrido, sin cambiar los límites de persistencia. No demuestra que el pool bajo LSAPI deje de crecer.

Pasaron las pruebas de ritmo, sondeo, orden de avisos, identidad de transferencia y admisión. La prueba de notificaciones de Ventas no completó: su fixture no crea las tablas de eventos y transferencias requeridas por la rama. Se mantiene como pendiente, junto con el ensayo HTTP, webhooks, Inicio y la matriz LSAPI de una hora. Windows cuenta con PHP CLI y MariaDB local, pero WSL no está instalado y no hay vhost LSAPI. El publicador sigue bloqueado.
