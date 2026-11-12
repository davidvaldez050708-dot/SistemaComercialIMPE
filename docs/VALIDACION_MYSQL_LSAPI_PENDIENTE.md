# Validación de capacidad MySQL · candidato a88b5fd

**Decisión: pendiente de la prueba de estabilidad en Linux/LSAPI.**

## Evidencia local de esta revisión

| Prueba | Resultado |
| --- | --- |
| Notificaciones, programación y conciliación de Ventas | 48 comprobaciones aprobadas en MariaDB local con datos sintéticos |
| Identidad de transferencia | 12 comprobaciones aprobadas en MariaDB local |
| Orden de la campana | 50 permutaciones aprobadas |
| Permisos del estado saliente | 5 comprobaciones aprobadas |
| Admisión MySQL | Terminación, plazo, permisos, aislamiento de estado incompleto y recuperación aprobados |

El fixture de Ventas aprovisiona disponibilidad, eventos y transferencias en su esquema de prueba. El cambio sólo afecta al fixture. La auditoría anterior midió 102 → 6 sentencias por sondeo. En las diez rotaciones FastCGI de la auditoría anterior, el candidato devolvió 300 respuestas HTTP 200 y quedaron 30 conexiones al final de cada ronda. La línea base alcanzó 65 conexiones observadas en la primera ronda y devolvió 25 HTTP 500. Esas cifras corresponden a Windows y el valor final de cada ronda no equivale a su pico.

El arnés LSAPI ahora registra los picos observados de conexiones totales, activas y Sleep durante cada ráfaga, y conserva ID, host y comando de las sesiones MariaDB en cada muestra. El proxy informa separadamente los máximos de intentos y admisiones en ventanas móviles de un segundo. Una admisión TCP no acredita por sí sola una sesión autenticada. Para atribuir sesiones a PIDs de LSPHP falta unir el puerto cliente de cada sesión con los sockets de ambos lados del proxy y el PID de origen en Linux. El muestreo puede omitir picos entre muestras.

## Réplica efímera

La etiqueta oficial `litespeedtech/openlitespeed:1.8.3-lsphp82` está disponible en Docker Hub con digest `sha256:c668df49470522bc380de38d3d2a18297a43913aefda61071e766b9d22ce7c86`. Una VM Linux desechable o un runner Ubuntu con Docker puede ejecutar esa imagen y MariaDB aislada. El arnés actual requiere un vhost interactivo. Para CI hay que automatizar arranque, reciclaje y parada, y preparar un esquema exclusivamente DDL con fixtures sintéticos. El vhost debe usar LSPHP ProcessGroup con mysqlnd y estadísticas activas, mantener las sesiones entre reciclajes, y escuchar únicamente en el entorno aislado.

1. Crear una instancia MariaDB desechable con el usuario sintético `impe_capacity`, `MAX_USER_CONNECTIONS 75` y el proxy local de 20 admisiones por segundo. Capturar contadores de aperturas y rechazos desde antes de iniciar el vhost hasta su parada.
2. Correr primero los perfiles frío, caliente y de reciclaje con el arnés actual. Ampliar después el escenario con pestañas, nuevos inicios de sesión y operaciones sintéticas de telefonía. Ejecutar una hora por variante con 30 usuarios y diez rotaciones, registrando PIDs y eventos de reciclaje.
3. Medir sentencias ejecutadas y escrituras con el registro general de MariaDB de laboratorio, correlacionado por ID de sesión y solicitud. Separar medición por endpoint, telemetría por segundo y los fallos inducidos de las rondas normales.
4. Verificar cada respuesta contra sus datos esperados y conciliar llamadas, avisos, resultados, grabaciones y transferencias por identificador, incluidos reintentos de webhooks. Comparar la línea base y el candidato con el mismo perfil y los mismos límites.

Windows carece de Docker y WSL en el entorno de trabajo de esta revisión. La imagen se verificó en el registro, pero el vhost no se ejecutó. Quedan sin medir los picos de LSAPI, SQL por segundo, aperturas por proceso, margen frente a 75/20 y errores 403/500/503 de la hora de carga. La validación de los límites simulados tampoco equivaldría a reproducir todas las reglas antiabuso del proveedor. El publicador sigue bloqueado.
