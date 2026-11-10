# Candidato para reducir conexiones MySQL

Base de Git `d8d7b398` en `codex/mysql-auditoria-capacidad`. Base de integración y pruebas HTTP `impe-codigo-revision.tar.gz`, SHA-256 `6d68131e8206990cba728ca1a6560addd5dbdf9ec79d2a78ea5b1e273f01ad69`.

Los manifiestos de Hostinger indicaron que 40 de 41 archivos seguían coincidiendo con las publicaciones anteriores. El archivo distinto, `TelefoniaControlService.php`, tiene huella `442c431767c9d091bd44a4f748f86159bbf8887a38751292c8476d21cd586b88` en la copia recibida. Se conservó y el publicador verifica su huella junto con la de los archivos que son contexto para el cambio.

## Cambios

- La campana comprueba la sesión y los permisos antes de construir servicios. Comparte una conexión entre los recorridos compatibles, conserva los avisos operativos y evita el DDL del recorrido de notificaciones de telefonía. Los servicios mantienen sus valores por defecto para otros módulos. La Agenda de la copia instalada conserva comentarios, horarios y destinatarios.
- Los endpoints directos de telefonía vuelven a comprobar el estado del usuario, su rol y los permisos vigentes mediante la conexión que usan para consultar la extensión. El inicio de sesión reutiliza su conexión para resolver el destino inicial. Un fallo temporal de conexión devuelve 503 con `Retry-After` en los endpoints adaptados. Las denegaciones de acceso conservan su estado.
- La campana comparte resultados entre pestañas de la misma sesión cuando el navegador tiene Web Locks y BroadcastChannel. Los refrescos explícitos conservan una consulta propia y las notificaciones urgentes de telefonía conservan sus temporizadores.
- Se conserva la política instalada de persistencia. La simulación de arranque escalonado espacia los usuarios en el banco de pruebas. Es una comparación experimental y no una medida de control de admisión implementada para producción.

## Verificación y publicación

El parche exacto de la copia instalada y las huellas están en `tools/mysql_capacidad`. El diff de la rama sirve para revisar las modificaciones al código de Git. El parche exacto se usa para revisar las variantes de Hostinger.

```bash
git diff d8d7b398..HEAD -- app prueba_telefonia/api public/javascript
python3 tools/publicar_mysql_capacidad.py --site "$HOME/domains/fundacionrededumex.org/public_html" --check
```

`--check` solo lee el sitio y valida una aplicación del parche en un directorio temporal. Ante una diferencia de huella, se detiene y lista las rutas. El manifiesto bloquea `--apply` mientras la validación funcional y de capacidad siga pendiente. El código del publicador guarda los archivos anteriores fuera del sitio y comprueba la huella del paquete y de los archivos instalados antes de una restauración. No se ha publicado en Hostinger.

## Resultados del laboratorio

PHP 8.2 y MariaDB 10.4 en localhost. Un servidor PHP por usuario, datos sintéticos y proveedor WebRTC simulado. El esquema usa el historial de definiciones del repositorio y omite las claves foráneas de las migraciones en el laboratorio. Por ello no certifica integridad relacional, rendimiento de LiteSpeed ni el límite por segundo del proveedor. La cuenta de MariaDB tiene configurado `MAX_USER_CONNECTIONS 75`. La tasa se estima mediante muestras del contador `Connections`, que también incluye los intentos rechazados y puede subestimar una ráfaga entre muestras. El p95 cuenta únicamente respuestas exitosas.

### Inicios de sesión con pools fríos

| Variante | Usuarios | Inicio | Pico de sesiones | Intentos en 1 s | Inicios exitosos | p95 |
|---|---:|---|---:|---:|---:|---:|
| Instalada | 10 | Simultáneo | 54 | 54 | 4/10 | 282 ms |
| Candidato | 10 | Simultáneo | 12 | 12 | 3/10 | 235 ms |
| Instalada | 30 | Simultáneo | 71 | 71 | 4/30 | 296 ms |
| Candidato | 30 | Simultáneo | 32 | 32 | 2/30 | 281 ms |
| Candidato | 10 | Separado por 110 ms | 19 | 17 | 8/10 | 172 ms |
| Candidato | 30 | Separado por 110 ms | 55 | 18 | 23/30 | 172 ms |

El volumen de solicitudes exitosas varía, de modo que estas cifras de conexión no demuestran por sí solas una reducción con trabajo equivalente. En ambas variantes se observaron 500 en la inicialización del catálogo de permisos al iniciar sesión. Hubo 500 de Inicio y respuestas parciales o no disponibles de la campana en algunos perfiles. La simulación escalonada de 30 usuarios quedó cerca del límite de 20/s en esta ejecución y lo superó en una ejecución preliminar. Los pools fríos simultáneos de 30 usuarios lo superaron incluso en el candidato.

### Verificaciones focalizadas

- Campana en VM JavaScript sobre la versión exacta de Hostinger. Dos pestañas concurrentes de la misma sesión comparten una solicitud, otra sesión queda aislada, un refresco explícito conserva su propia consulta y los avisos operativos sobreviven a un 503. Reproducir con `node tests/mysql_campana_pestanas.cjs /ruta/al/recordatorios.js`.
- WebRTC HTTP simulado. Sin sesión devuelve 401. Con permiso válido devuelve 200, tras revocarlo con la misma sesión devuelve 403 y tras reponerlo vuelve a 200. Una caída de MySQL devuelve 503 con `Retry-After`. La misma cookie vuelve a funcionar cuando se restablece la conexión.
- Pruebas existentes de operaciones de transferencia y cierre SIP en Node. Ambos recorridos pasaron con simulaciones. Esto no certifica grabaciones, resultados ni llamadas reales de todos los perfiles.
- Publicador probado sobre una copia temporal. Se verificaron el bloqueo de aplicación, el rechazo de una modificación en el archivo personalizado, el respaldo y la restauración exacta de las huellas. El permiso de aplicar se habilitó exclusivamente en el manifiesto desechable del ensayo.

### Recargas simultáneas con sesiones válidas y pools fríos

| Variante | Usuarios | Inicio | Pico de sesiones | Intentos en 1 s | Campana inicial disponible | p95 |
|---|---:|---|---:|---:|---:|---:|
| Instalada | 10 | Simultáneo | 75 | 91 | 3/10 | 203 ms |
| Candidato | 10 | Simultáneo | 20 | 20 | 8/10 | 125 ms |
| Instalada | 30 | Simultáneo | 81 | 133 | 0/30 | 297 ms |
| Candidato | 30 | Simultáneo | 62 | 62 | 26/30 | 265 ms |
| Candidato | 10 | Separado por 110 ms | 20 | 18 | 8/10 | 93 ms |
| Candidato | 30 | Separado por 110 ms | 62 | 21 | 26/30 | 125 ms |

Con 30 sesiones válidas el candidato redujo el pico observado de 81 a 62 y las campanas con 503 de 60 a cero en dos consultas por usuario. De las 60 respuestas del candidato, 52 estuvieron disponibles y 8 devolvieron un JSON de indisponibilidad. Inicio conservó 8 respuestas 500 de 30 en las dos variantes. El contador de sesiones incluye el estado transitorio que muestra MariaDB al rechazar conexiones. La base instalada terminó en 75 sesiones ociosas y el candidato en 62.

### Pendiente para aprobar

- Aislar el error SQL de la inicialización de permisos bajo inicios de sesión concurrentes y reproducir los fallos de Inicio y de campana con un esquema de pruebas más representativo. La exportación recibida contiene código. La matriz usa ocho perfiles sintéticos y no confirma todos los permisos, avisos y roles realmente configurados.
- Conservar la persistencia mientras se mide su reutilización y el reciclaje de workers en un entorno representativo. Para 30 pools fríos, incluso una apertura por usuario puede superar 20/s. El escalonamiento del cliente reduce ráfagas de sondeos no críticos. Para asegurar el límite también hace falta controlar la admisión de aperturas nuevas o demostrar que el pool caliente absorbe la ráfaga, reservando capacidad para cron y transacciones.
- Completar la regresión de telefonía y campana en todos los recorridos y repetir con límites reproducibles. Se mantiene el bloqueo de aplicación hasta entonces.

El ensayo está en `tests/mysql_capacidad_http.py`. Requiere una instancia desechable de MariaDB en localhost con un directorio de datos que termine en `mysql-capacidad-<hex>/data`, una base vacía `impe_test_capacidad` y una cuenta local `impe_capacity` con permisos sobre esa base y máximo de 75 conexiones. Usa `--base` para la copia instalada y `--cambios` para una copia de laboratorio a la que se haya aplicado el parche. Ejecutar cada variante del ensayo contra una base vacía. Con `--preautenticar` mide recargas sin incluir el inicio de sesión en la ventana medida.

## Segunda revisión sobre `d9d827cb`

### Excepciones reproducidas

Se instrumentó una copia desechable de los PHP instalados para registrar los códigos y mensajes completos. Los registros detallados quedaron en el laboratorio.

| Recorrido | Excepción reproducida | Origen |
|---|---|---|
| Autenticación | `mysqli_sql_exception 1213`, interbloqueo | `RolModel.php:94` ejecuta el `INSERT … ON DUPLICATE KEY UPDATE` del catálogo en cada login. La inicialización también normaliza roles dentro de una transacción. Las solicitudes concurrentes escribían las mismas filas aun con el catálogo instalado. |
| Inicio de Analista | `mysqli_sql_exception 1054`, columna `realizada_por` | `AnalistaDashboardModel.php:503`. El esquema anterior del laboratorio omitió el ALTER de la migración de integridad de Agenda. |
| Inicio de Marketing | `mysqli_sql_exception 1054`, columna `estado` | `ConvocatoriaModel.php:1480`. La definición histórica de `convocatorias` tenía `estatus` y la prueba saltaba la definición moderna. |
| Campana administrativa | `mysqli_sql_exception 1054`, columna `estado` | El mismo esquema histórico de Convocatorias impedía calcular el resumen. |
| Campana de Asesor | `DomainException`, migración de telefonía comercial ausente | Faltaba la tabla `telefonia_ventas_llamadas`, creada por la migración PHP `TelefoniaVentasSchema`. |

El código de autenticación consulta el catálogo y los permisos vigentes. Solo llama al inicializador si faltan códigos del catálogo. La sesión autenticada se escribe después de completar las lecturas de permisos y el registro del acceso. Se conserva el aprovisionamiento cuando faltan códigos y la gestión administrativa de permisos. Los `1054` de Inicio se corrigieron en el esquema de ensayo, sin cambiar las consultas ni convertir sus excepciones en respuestas exitosas. Esos fallos se observaron en el laboratorio, y queda pendiente cotejar la estructura efectiva de Hostinger.

Los recorridos de lectura de Inicio usan la conexión de la petición en Administrador, Analista y Cuenta Clave, y en las consultas compatibles de Dirección y Marketing de la variante instalada. Se conservan las conexiones independientes de las operaciones transaccionales. La campana dispersa únicamente los sondeos de fondo y deduplica respuestas recientes entre pestañas de la misma sesión. La apertura manual de la campana y los eventos urgentes se atienden por sus recorridos inmediatos.

### Límites explícitos y margen

MariaDB del laboratorio aplica `MAX_USER_CONNECTIONS 75` al usuario del CRM. Un intermediario TCP compartido entre los clientes del ensayo cuenta los intentos de conexión y rechaza con error MySQL 1226 cuando se han admitido 20 aperturas en la ventana móvil anterior. `--metricas` registra por segundo aperturas, sesiones, Sleep, éxitos, fallos, códigos HTTP y latencias. La meta de margen del ensayo es mantener como máximo 60 conexiones y 15 aperturas por segundo. El intermediario solo se usa en el laboratorio y no simula la asignación y el reciclaje de procesos de LiteSpeed ni la actividad de otros consumidores en la cuenta real.

`--pool-caliente` hace un arranque medido y gradual de los workers de prueba antes de iniciar la ráfaga de usuarios. El escalonamiento de este banco no se aplica a la autenticación del producto. En frío, 30 workers que necesitan conectar a la vez pueden superar el límite aun con una conexión por petición. Hace falta validar la reutilización y el reciclaje del pool bajo las condiciones del alojamiento, o disponer de un mecanismo de pooling compatible con transacciones y bloqueos que reduzca las aperturas físicas sin retrasar las rutas urgentes.

### Comparación con límites activos

Los resultados corresponden al esquema sintético corregido. Cada fila usa pools nuevos o el calentamiento explícito indicado. La columna de campana cuenta la primera consulta de cada usuario. Las pruebas de carga posteriores también consultan la campana por segunda vez. Los 500 y 503 se cuentan en las rutas de carga. Los fallos provocados para verificar recuperación se prueban aparte.

| Variante y condición | Usuarios | Login | Inicio | Campana | Pico sesiones | Intentos en 1 s | Rechazos MySQL | HTTP 500 / 503 | p95 |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Instalada, frío simultáneo | 10 | 4/10 | 4/10 | 0/10 | 20 | 28 | 8 | 6 / 8 | 391 ms |
| Candidato, frío simultáneo | 10 | 10/10 | 10/10 | 10/10 | 16 | 16 | 0 | 0 / 0 | 218 ms |
| Instalada, frío simultáneo | 30 | 2/30 | 1/30 | 0/30 | 20 | 38 | 18 | 18 / 15 | 422 ms |
| Candidato, frío simultáneo | 30 | 20/30 | 17/30 | 20/30 | 20 | 39 | 19 | 0 / 13 | 187 ms |
| Candidato, frío escalonado a 110 ms | 30 | 30/30 | 30/30 | 30/30 | 46 | 16 | 0 | 0 / 0 | 79 ms |
| Candidato, caliente simultáneo | 10 | 10/10 | 10/10 | 10/10 | 16 | 4 | 0 | 0 / 0 | 94 ms |
| Candidato, caliente simultáneo | 30 | 30/30 | 30/30 | 30/30 | 46 | 4 | 0 | 0 / 0 | 313 ms |

En las dos pruebas calientes, las aperturas se concentraron en el calentamiento y no se registraron aperturas nuevas en la ráfaga posterior. En frío, el límite protege MySQL y deja solicitudes sin atender. La fila escalonada completó todos los recorridos y excedió por una apertura el margen objetivo de 15/s. El p95 de cada fila solo incluye respuestas exitosas, así que un tiempo menor con muchos rechazos no indica una mejor experiencia.

Las pruebas HTTP verificaron cierre de sesión, rechazo de la cookie anterior, revocación de permiso con sesión activa, 503 por caída de la conexión en autenticación, campana y WebRTC, y recuperación sin perder la sesión válida. Los ensayos Node verificaron coordinación de pestañas, conservación de avisos operativos, transferencia simulada y cierre SIP. Las consultas de telefonía HTTP usaron proveedor simulado y perfiles representativos. La distribución de avisos reales de todos los roles queda pendiente de validar con un esquema representativo y datos de prueba por rol.

Se verificó el paquete de 28 archivos contra las huellas de la copia de Hostinger. La huella de `TelefoniaControlService.php` sigue siendo un requisito de integridad del paquete. `--check` pasó y `--apply` sigue bloqueado. La ráfaga en frío, el margen para otros consumidores y la equivalencia del esquema siguen pendientes de aceptación.

### Archivos de la segunda revisión

- `app/controllers/HomeController.php`
- `app/controllers/LoginController.php`
- `app/models/AnalistaDashboardModel.php`
- `app/models/RolModel.php`
- `app/services/AnalistaDashboardIntegrityService.php`
- `app/services/AnalistaDashboardReunionService.php`
- `app/services/CuentaClaveDashboardService.php`
- `app/services/SeguimientoAtencionOperativaService.php`
- `public/javascript/recordatorios.js`
- `tests/mysql_campana_pestanas.cjs`
- `tests/mysql_capacidad_http.py`
- `tests/mysql_limite_aperturas.py`
- `tools/mysql_capacidad/hostinger.patch`
- `tools/mysql_capacidad/huellas.json`
- `docs/VALIDACION_MYSQL_CANDIDATO.md`

Para reproducir con los límites, usar `--limitar --metricas resultados.jsonl`. Para el perfil caliente usar también `--pool-caliente --sin-escalonar`. La opción `--preautenticar` separa las recargas de la autenticación. La opción `--esquema` admite las definiciones de tablas obtenidas de `SHOW CREATE TABLE` y las instala únicamente en la base vacía del laboratorio.

Con sesiones autenticadas antes de la ventana de carga, también pasaron las recargas simultáneas de 10 y 30 usuarios en caliente. Inicio y las dos consultas de campana respondieron correctamente para todos. Hubo 16 y 46 sesiones respectivamente, cuatro intentos/s durante el calentamiento, ningún rechazo y un p95 de 109 y 281 ms. Las consultas simuladas de telefonía y transferencias devolvieron 200. La ejecución de sesiones válidas y el perfil frío final también completaron las pruebas de caída y recuperación de conexión.

## Validación con la estructura exportada de Hostinger

Archivo recibido `impe-esquema-revision.sql`, SHA-256 `61bd6ca46167fd642b441d2a4994681d1a5b072d67888b924b6c1915169b4210`. Se verificaron 128 definiciones `CREATE TABLE` y se importaron sin cambios en MariaDB 10.11.16 portátil. La primera importación en MariaDB 10.4.32 devolvió 1273 porque esa instancia local no reconocía `utf8mb4_uca1400_ai_ci`. Las cifras de esta sección corresponden a la exportación original, incluida esa collation.

El esquema contiene `convocatorias.estado`, `reuniones_vinculacion.realizada_por` y la tabla `telefonia_ventas_llamadas`. Esto confirma que las tres omisiones de los primeros ensayos estaban en el esquema sintético. Los datos y permisos del ensayo siguen siendo sintéticos.

Con `--verificar-avisos` se sembró un comentario distinto y con hora para cada usuario. Se comprobó en las dos lecturas de la campana que el comentario y el horario correspondían al destinatario, sin avisos de otros usuarios. También se comprobó que el sondeo no creó lecturas ni registros de atención para esos comentarios.

| Variante y condición | Usuarios | Login | Inicio | Campana con aviso correcto | Sesiones pico | Intentos en 1 s | Rechazos de apertura | HTTP 500 / 503 | p95 |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Instalada, frío simultáneo | 10 | 2/10 | 2/10 | 0/10 | 20 | 24 | 4 | 8 / 4 | 344 ms |
| Candidato, frío simultáneo | 10 | 10/10 | 10/10 | 10/10 | 16 | 16 | 0 | 0 / 0 | 250 ms |
| Instalada, frío simultáneo | 30 | 1/30 | 0/30 | 0/30 | 20 | 33 | 13 | 19 / 13 | 391 ms |
| Candidato, frío simultáneo | 30 | 20/30 | 19/30 | 20/30 | 20 | 39 | 19 | 0 / 11 | 250 ms |
| Candidato, frío escalonado a 110 ms | 30 | 30/30 | 30/30 | 30/30 | 46 | 15 | 0 | 0 / 0 | 172 ms |

El escenario escalonado alcanzó el margen definido de 60 sesiones y 15 aperturas/s. El espaciado solo pertenece al ensayo. La ráfaga en frío sigue sin cumplir el criterio de aceptación. Se confirmó el cierre de sesión, revocación, fallos temporales 503 y recuperación. La exportación no contiene los registros y los perfiles reales del CRM, ni reproduce el ciclo de vida de los workers de LiteSpeed. El publicador conserva el bloqueo de aplicación.

Con sesiones previamente autenticadas y pools calientes, pasaron todas las recargas, Inicio y las dos consultas de campana de 10 y 30 usuarios. Se observaron 16 y 46 sesiones, respectivamente, y cuatro aperturas/s durante el calentamiento, sin aperturas nuevas en la ráfaga ni rechazos. El p95 fue de 109 y 313 ms. Para 30 usuarios respondieron correctamente 12 consultas por cada recorrido simulado de WebRTC, entrada activa, estado y transferencia. La verificación deliberada de 401, 403 por revocación y 503 por caída de MySQL pasó aparte. Estos resultados validan los avisos sembrados y las rutas simuladas en el laboratorio, con la aceptación del alojamiento aún pendiente por el caso frío.

## Admisión de aperturas y reciclaje de workers

### Causa del pico en frío

La conexión compartida evita abrir varias sesiones compatibles en la misma petición. El pool persistente de PHP pertenece a cada worker. Al arrancar 30 workers fríos, cada primer login necesita su propio socket. Las rutas con una conexión independiente pueden necesitar otro socket aunque el worker ya tenga uno ocupado. En esta configuración, un pool frío de 30 workers supera el límite de 20/s aun si cada login abre una sola sesión.

La traza del laboratorio diferencia la conexión compartida de la independiente y clasifica cada `real_connect` por el delta de `connection_reused` de mysqlnd. El intermediario TCP cuenta intentos físicos, incluidos los rechazos. Los dos indicadores tienen alcances distintos. La traza no incluye credenciales, parámetros, cookies, SQL ni usuarios.

| Ruta | Nuevas compartidas | Nuevas independientes | Reutilizadas compartidas | Reutilizadas independientes |
|---|---:|---:|---:|---:|
| Autenticación | 32 | 0 | 1 | 0 |
| Inicio | 0 | 6 | 32 | 0 |
| Campana y recarga | 0 | 2 | 64 | 2 |
| WebRTC, entrada y estado | 0 | 0 | 43 | 0 |
| Consulta de transferencia | 0 | 0 | 0 | 13 |

La tabla agrega los ensayos sin admisión de 10 y 30 usuarios y las sondas de verificación. Las rutas de telefonía se ejecutan después de Inicio y campana en ese recorrido. Un worker que reciba una petición telefónica como primera solicitud todavía puede necesitar una apertura nueva. Los intentos fallidos se ven en la traza separadamente.

### Admisión opcional

`IMPE_DB_ADMISION_DIR` activa la coordinación en un directorio privado fuera del sitio. La admisión usa un candado de archivo al reservar y una marca independiente por intento. El worker escribe un archivo de confirmación separado y no vuelve a competir por el candado. La siguiente reserva descuenta las confirmaciones de reutilización. Cada archivo se escribe mediante renombrado atómico. Ninguna conexión ni consulta SQL se ejecuta bajo el candado. Se admiten hasta 15 posibles aperturas por 1,05 s, 13 para peticiones normales y 15 para las rutas telefónicas urgentes. Una conexión en caché puede haber caducado, por lo que todo real_connect requiere admisión. El cupo se devuelve cuando mysqlnd confirma una reutilización y se conserva durante la ventana en caso de apertura nueva, intento fallido o medición no disponible.

La espera máxima de admisión es 4,2 s en autenticación, Inicio, campana y demás rutas normales, y 240 ms para las rutas telefónicas prioritarias. El presupuesto cubre la espera por el cupo. Una vez concedido, MySQLi conserva su propio tiempo de conexión. No se repite ninguna operación SQL ni petición HTTP. Las esperas se dispersan y ocurren antes de intentar abrir una conexión. Si el proceso muere antes de confirmar, la reserva se conserva hasta 30 s. El fallo de admisión responde 503 por el recorrido temporal existente.

El margen de cinco aperturas por segundo contempla otros consumidores de la cuenta, cuyos intentos no pasan necesariamente por este componente. Hace falta verificar que todos los workers usan el mismo directorio y que su sistema de archivos soporta `flock` y renombrados atómicos. Se requiere un solo host coordinado para este diseño. La publicación sigue bloqueada hasta validar esos requisitos, el comportamiento de LiteSpeed y las rutas urgentes con la topología real.

En el laboratorio, `--traza` habilita los eventos de mysqlnd, `--admision` activa el control y `--pool-caliente --reciclar 10` detiene diez workers y crea reemplazos antes de la ráfaga. `--preautenticar` conserva cookies válidas para el ensayo de recargas. El estado de admisión se crea fuera de la raíz del sitio temporal. La prueba usa la exportación original de 128 tablas y ocho perfiles sintéticos, el limitador TCP de 20 aperturas/s y el límite real de 75 conexiones del usuario de MariaDB.

### Comparación del control inicial

Los casos sin admisión usan el mismo candidato funcional y el mismo esquema, con el control apagado. El pico en la ventana móvil lo informa el intermediario TCP. El p95 se calcula sobre respuestas exitosas.

| Condición | Usuarios | Login | Inicio | Campana inicial | Sesiones pico | Intentos físicos/s | Rechazos MySQL | HTTP 500 / 503 | p95 |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Frío simultáneo, sin admisión | 10 | 10/10 | 10/10 | 10/10 | 16 | 16 | 0 | 0 / 0 | 234 ms |
| Frío simultáneo, sin admisión | 30 | 20/30 | 18/30 | 20/30 | 20 | 41 | 21 | 0 / 12 | 172 ms |
| Frío simultáneo, con admisión | 10 | 10/10 | 10/10 | 10/10 | 16 | 13 | 0 | 0 / 0 | 1.250 ms |
| Frío simultáneo, con admisión | 30 | 30/30 | 30/30 | 30/30 | 46 | 13 | 0 | 0 / 5 | 2.406 ms |
| Diez workers reemplazados, sesiones válidas | 10 | — | 10/10 | 10/10 | 16 | 13 | 0 | 0 / 1 | 1.032 ms |
| Diez workers reemplazados, sesiones válidas | 30 | — | 30/30 | 30/30 | 46 | 13 | 0 | 0 / 7 | 1.985 ms |
| Diez workers reemplazados, login simultáneo | 10 | 10/10 | 10/10 | 10/10 | 16 | 13 | 0 | 0 / 0 | 1.125 ms |
| Diez workers reemplazados, login simultáneo | 30 | 30/30 | 30/30 | 30/30 | 46 | 6 | 0 | 0 / 5 | 1.813 ms |

En este primer diseño de estado compartido, los 503 con admisión se produjeron en consultas urgentes de entrada, estado o transferencia por agotamiento de su plazo local. Los distingue el resultado `sin_cupo` en la traza. No se reintentaron operaciones automáticamente. La mayor espera de admisión observada en login frío fue de 4.142 ms. Aumentar la reserva para telefonía a cuatro cupos y dar prioridad a sesiones posiblemente reutilizables no mejoró los resultados de forma consistente, por lo que se conservó la política de 13 cupos normales y dos adicionales para telefonía urgente.

Pasaron las pruebas de revocación, cierre de sesión, caída de MySQL y recuperación en los perfiles ejecutados, además de los ensayos Node de múltiples pestañas, transferencia simulada y cierre SIP. El control de admisión pasó el ensayo de cupo reservado, devolución por reutilización, caducidad y rechazo de un estado inválido. El paquete selectivo incluye los dos archivos de configuración de esta revisión y conserva la huella de `TelefoniaControlService.php`.


### Resultados con confirmaciones separadas

| Condición | Usuarios | Login | Inicio | Campana inicial | Sesiones pico | Intentos físicos/s | Rechazos MySQL | HTTP 500 / 503 | p95 |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Frío simultáneo | 10 | 10/10 | 10/10 | 10/10 | 16 | 13 | 0 | 0 / 0 | 875 ms |
| Frío simultáneo | 30 | 30/30 | 30/30 | 30/30 | 46 | 12 | 0 | 0 / 5 | 2.875 ms |
| Pools calientes, sesiones válidas | 10 | — | 10/10 | 10/10 | 16 | 4 | 0 | 0 / 0 | 266 ms |
| Pools calientes, sesiones válidas | 30 | — | 30/30 | 30/30 | 46 | 4 | 0 | 0 / 1 | 1.141 ms |
| Diez workers reemplazados, sesiones válidas | 10 | — | 10/10 | 10/10 | 16 | 13 | 0 | 0 / 1 | 1.219 ms |
| Diez workers reemplazados, sesiones válidas | 30 | — | 30/30 | 30/30 | 46 | 12 | 0 | 0 / 2 | 1.234 ms |
| Diez workers reemplazados, login simultáneo | 10 | 10/10 | 10/10 | 10/10 | 16 | 13 | 0 | 0 / 0 | 1.063 ms |
| Diez workers reemplazados, login simultáneo | 30 | 30/30 | 30/30 | 30/30 | 46 | 10 | 0 | 0 / 3 | 1.250 ms |

El p95 en frío para el login de 30 usuarios fue de 3.532 ms y la espera de admisión máxima observada en ese recorrido fue de 3.984 ms. El p95 global incluye únicamente respuestas exitosas. En caliente, el pico físico incluye el calentamiento anterior a la ráfaga. Las recargas de cada usuario se validaron con su aviso de agenda dirigido y horario, y sin marcarlo como leído o atendido.

La admisión eliminó rechazos MySQL en esta matriz aislada. Todavía se observaron 503 locales en rutas telefónicas y un aumento de latencia respecto a un pool caliente sin control. Falta verificar el rendimiento y la coordinación con el filesystem, la distribución de peticiones y el reciclaje real de LiteSpeed. Los resultados de transferencias y múltiples pestañas son de simulación y los perfiles son sintéticos. Se mantiene bloqueado el publicador.
