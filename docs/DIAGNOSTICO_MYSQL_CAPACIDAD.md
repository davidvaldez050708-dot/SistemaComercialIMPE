# Diagnóstico de capacidad MySQL en Hostinger

## Estado observado

Auditoría de `6d6851ac9dec2db23842b2d1078acedf97770140`, en la rama local de
revisión `codex/mysql-auditoria-capacidad`. El código de esta rama sirve como
referencia para el ensayo. El punto de partida de una publicación será la
copia instalada de Hostinger, con huellas y personalizaciones verificadas.

El 10 de noviembre de 2026 se contrastaron las huellas del sitio con los
manifiestos de las dos publicaciones. **40 de 41 archivos** coincidieron con
el valor `despues` de la última publicación aplicable a cada archivo. La
excepción es `app/services/TelefoniaControlService.php`. Su constructor en
Hostinger crea una conexión y la pasa a Extensiones, Marcaciones y Resultado
de Ventas. Falta comparar los demás métodos de ese archivo antes de editarlo.

`config/db_connection.php`, el servicio del panel del Asesor, Extensiones y
los cuatro endpoints Zadarma muestreados coinciden con `6d6851ac`. Los
controladores, sondeos y servicios con huellas personalizadas requieren
integración sobre sus versiones reales. El `HomeController.php` instalado
coincide con el blob de `31daa607`. `ReminderController.php` y
`recordatorios.js` presentan huellas distintas de la rama. Se confirmó el
constructor de la campana mediante lectura del archivo instalado.

El plan observado permite 60 workers PHP, 75 sesiones MySQL por usuario
y 20 conexiones nuevas por segundo por cuenta. Los registros reportados
contienen 341 coincidencias con `max_user_connections`, 729 con
`Operation not permitted` el 9 de noviembre y 48 fallos de conexión 2002
el día 10. El código 2002 indica un fallo al conectar y por sí solo no
distingue un rechazo de tasa de otros fallos de transporte. Los 403
históricos del origen son un incidente separado.

## Aperturas y sondeos

| Flujo | Evidencia |
| --- | --- |
| Inicio de sesión y sesión autenticada | En `6d6851ac` Usuario y Rol reciben el mismo handle. El `index.php` de Hostinger y su Login coinciden con los manifiestos de la integración, con personalizaciones propias. |
| Inicio del Asesor | El panel usa una conexión compartida para extensión, historial y contactos. Otras ramas de Inicio crean sus servicios propios. El inicio del Administrador crea otro UsuarioModel para lecturas posteriores a la validación de sesión. |
| Disponibilidad | La integración de Hostinger conserva el sondeo con referencia y permite reutilizar la sesión del índice para `POLL`. Las mutaciones y la autorización de transferencias usan sesiones con alcance propio. Mantener la validación de permisos, CSRF, presencia y caducidad. |
| WebRTC, entrada y estado de Zadarma | En los tres archivos instalados, la ruta sin `$_SESSION['permisos']` construye `new RolModel()` y después el endpoint usa `Database::compartida()`. Es un segundo handle simultáneo en ese recorrido, que suele quedar retenido en el pool al terminar. La ruta con permisos en sesión ya usa el handle compartido para extensión y eventos. |
| Webhook | Tras las validaciones, abre una conexión propia para registrar el evento. La deduplicación del evento y el orden de autorización siguen siendo necesarios. |
| Campana | El constructor instalado crea ocho servicios antes de resolver permisos. En la rama, siete abren una conexión cada uno y el servicio de Aliados abre dos. Con la conexión de autenticación, eso supone diez handles en el recorrido auditado de referencia, antes de servicios adicionales. El número de aperturas físicas depende del pool caliente y del código exacto de cada servicio en Hostinger. |

La hipótesis principal es la **multiplicación de handles por petición**.
`Database::compartida()` solo reutiliza llamadas que optan por ese método o
que reciben el mismo objeto por inyección. Cada `connect()` independiente
puede ampliar el pool del proceso si sus otras sesiones siguen prestadas.
La coincidencia temporal de arranques y sondeos también presiona el límite
de aperturas. Medir aperturas, sesiones vivas y rechazos por separado.

En la rama, presencia se consulta cada 3 s, entrada cada 1,8 s y
transferencia tiene un reloj de 1 s con guardas de actividad. El marcador
controla el estado saliente según fase, WebRTC deduplica solicitudes en
vuelo dentro de la página y la campana base sondea cada 60 s y al recuperar
visibilidad. Los relojes de renderizado locales no cuentan como solicitudes
HTTP. Los JS instalados de teléfono, disponibilidad y campana son variantes.
La cadencia real y la duplicación entre pestañas deben verificarse con
peticiones de navegador en un entorno de revisión.

## Prueba aislada

MariaDB 10.4 y PHP 8.2 en localhost. Una cuenta desechable con
`MAX_USER_CONNECTIONS 75`. Cada usuario simulado ocupa un proceso PHP y
ejecuta cuatro solicitudes sintéticas con cuatro lecturas, con un mínimo
de 45 ms de ocupación simulada por solicitud correcta. Los procesos
persistentes devuelven sus handles al pool entre solicitudes y quedan
vivos para medir `Sleep`. El observador usa otra cuenta. Se muestrea
`PROCESSLIST` y el contador acumulado `Connections`; los intentos incluyen
rechazos, y el pico en ventanas de hasta 1 s es un límite inferior del
pico exacto. El laboratorio no reproduce el limitador de 20/s de Hostinger.

| Usuarios | Política | Pico de sesiones | Sleep final | Intentos totales | Intentos en 1 s observados | Solicitudes con error | p95 exitosas (ms) |
| ---: | --- | ---: | ---: | ---: | ---: | ---: | ---: |
| 10 | 4 persistentes, arranque simultáneo | 40 | 40 | 40 | 40 | 0 | 93,5 |
| 10 | 1 persistente, arranque simultáneo | 10 | 10 | 10 | 10 | 0 | 63,0 |
| 10 | 1 no persistente, arranque simultáneo | 10 | 0 | 40 | 40 | 0 | 77,6 |
| 10 | 1 persistente, arranque escalonado | 10 | 10 | 10 | 10 | 0 | 62,1 |
| 30 | 4 persistentes, arranque simultáneo | 90 | 75 | 196 | 168 | 106 (1226) | 92,5 |
| 30 | 1 persistente, arranque simultáneo | 30 | 30 | 30 | 30 | 0 | 72,8 |
| 30 | 1 no persistente, arranque simultáneo | 30 | 0 | 120 | 120 | 0 | 78,2 |
| 30 | 1 persistente, arranque escalonado | 30 | 30 | 30 | 11 | 0 | 62,2 |

En el escenario saturado, el observador contó transitoriamente 90 sesiones
de la cuenta de prueba. Al estabilizarse quedaron 75 y se registraron
rechazos 1226. El p95 solo incluye solicitudes exitosas; en ese escenario
fueron 14 de 120. Los escenarios liberaron las sesiones al salir los procesos.
No se probaron tiempos HTTP ni límites de LiteSpeed con este laboratorio.

Para repetirlo, iniciar una instancia desechable ligada a `127.0.0.1` con
un directorio de datos terminado en `mysql-capacidad-<hex>/data`. Crear
el esquema vacío `impe_test_capacidad` y el usuario local de laboratorio
`impe_capacity` sin contraseña, con límite 75 y solo SELECT en ese esquema.
El observador requiere root de esa instancia aislada. Ejecutar
`python tests/mysql_capacidad.py --php RUTA_PHP --port PUERTO_LOCAL`.
La herramienta no consulta endpoints del CRM ni servicios externos.

También pasaron las pruebas existentes de consulta/entrega y recuperación
tras 503, transición SIP, autorización de salientes y seguridad de
transferencia. Se ejecutaron sobre el commit auditado, sin llamadas reales.
La validación HTTP y funcional con las variantes de producción sigue
pendiente en un entorno aislado.

## Propuesta para revisión

1. Obtener una copia privada, con huellas, de los controladores, servicios y
   scripts que difieren. Usar los manifiestos para verificar el punto de
   partida. No copiar configuración privada, bases ni registros personales.
2. Dar prioridad a la campana. Resolver acceso antes de crear dependencias.
   Construir solo los servicios del recorrido autorizado e inyectar la
   misma sesión en operaciones auditadas sin transacción, cierre propio,
   DDL, resultados sin consumir ni estado de sesión especial. Mantener
   separadas las transacciones, los bloqueos y las acciones que las requieran.
3. En los tres endpoints Zadarma, comprobar si la lectura del rol puede
   reutilizar el handle de extensión/eventos. Revisar por separado la
   inicialización de permisos y su semántica. Un fallo transitorio en esa
   etapa debe devolver 503 antes de interpretar permisos vacíos como 403.
4. Conservar la persistencia en esta fase. Con 60 workers, un pool de una
   sesión por worker podría retener 60 sesiones, dejando 15 de margen
   nominal antes de contar transacciones, tareas y otras aplicaciones.
   Validar con Hostinger la capacidad real de limitar pools/workers y de
   reciclar workers de forma gradual. Probar los cambios de política por
   separado y medir los dos límites. No convertir el resultado escalonado
   del laboratorio en una promesa de tasa en producción.
5. Escalonar el arranque de sondeos de fondo y aplicar espera progresiva a
   503. Mantener el control de llamadas activas y transferencias. Medir
   solicitudes por pestaña y por usuario antes de cambiar intervalos o
   introducir coordinación entre pestañas. Evitar reintentos automáticos
   de acciones no idempotentes.
6. Probar las rutas HTTP reales en una copia aislada con 10 y 30 usuarios,
   datos sintéticos y Zadarma simulado. Validar 401, 403 y 503, inicio de
   sesión, recarga, agenda, llamadas, transferencias y registros. Medir
   p95, errores y sesiones por recorrido, incluyendo arranque en frío.
7. Tras revisar los resultados, preparar un publicador de esa entrega con
   lista cerrada de archivos, huellas de las variantes aprobadas, check
   de solo lectura, respaldo fuera del sitio y restauración condicionada
   a las huellas instaladas. La publicación previa queda como evidencia.
   No se usará como base ejecutable del próximo despliegue.

**Estado de entrega:** diagnóstico y ensayo de capacidad listos para
revisión. El candidato de ejecución y su publicador quedan pendientes
de contrastar los fragmentos personalizados y probar los recorridos HTTP.
