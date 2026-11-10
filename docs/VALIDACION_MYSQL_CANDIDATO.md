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

## Prioridades de telefonía y recuperación de cupos

### Diagnóstico y cambio

En `12c310e4`, la mayor parte de los 503 internos de telefonía se registró como `sin_cupo`. Los procesos competían por el mismo candado incluso cuando la conexión se reutilizaba. Las consultas de estado, los checkpoints previos a marcar y las acciones de transferencia compartían el presupuesto urgente de 240 ms. Esto podía agotar el plazo antes de que MySQLi intentara conectar. Con la primera prueba por cupos se aisló un segundo motivo. El login devolvió 503 de estado a los 26 ms al fallar la escritura atómica de una reserva. La traza anterior no distinguía la creación del temporal del reemplazo final. Ese caso fue previo al plazo y al intento de conexión.

La admisión usa 15 cupos independientes, cada uno con su propio candado y estado. Un intento mantiene solo el candado de su cupo mientras abre o restablece la sesión MySQLi. Los otros cupos siguen disponibles. El estado se escribe atómicamente antes de conectar. Se libera en cuanto mysqlnd confirma reutilización y se conserva 1,05 s después de los demás intentos. Si falla un reemplazo, se hacen hasta tres intentos breves de I/O local y luego se omite el cupo durante esa admisión. Esto no repite la petición ni ejecuta de nuevo operaciones SQL.

| Clase | Rutas | Presupuesto de admisión |
|---|---|---:|
| Crítica | Entrada activa, estado de llamada en curso, webhook Zadarma, POST de transferencia y acciones de marcado y transferencia | 240 ms |
| Preparación | Clave WebRTC y checkpoint previo a marcar | 1.250 ms y acceso preferente al cupo 13 |
| Sondeo | Consulta GET de transferencia, avisos, contexto y sondeos de disponibilidad | 1.250 ms, sin cupo reservado |
| Normal | Login, Inicio, campana y resto de rutas | 4.200 ms, sin cupo reservado |

Los cupos 14 y 15 quedan reservados para operaciones críticas. La ruta de WebRTC requiere autorización vigente y prepara el transporte antes de usar el SDK. El checkpoint fija la marca previa a la marcación. Las consultas GET de transferencia conservan su lógica existente, que puede actualizar el estado durante el sondeo, por lo que este cambio no añade reintentos de esas peticiones. El plazo de admisión se suma a la conexión y a la ejecución de la ruta. Las latencias de laboratorio se miden de extremo a extremo.

### Estadísticas y archivos

Con `mysqlnd.collect_statistics=0`, con la función deshabilitada o con un contador inválido, el control contabiliza todo intento como posible apertura y no devuelve anticipadamente el cupo. El proxy del laboratorio mide la apertura física de manera independiente. En este modo puede caer el número de peticiones atendidas aun cuando MySQL esté reutilizando conexiones. La disponibilidad de las estadísticas forma parte de la verificación antes de habilitar la admisión.

El sistema operativo libera el candado si el proceso termina. El estado pendiente permanece hasta 30 s para conservar margen si la conexión llegó a iniciarse. Un archivo de estado incompleto o ilegible deja fuera un cupo, mientras los demás pueden seguir operando. Un directorio ausente, dentro del sitio o con permisos abiertos en Unix impide activar el control. Un temporal incompleto queda fuera del estado activo. Si Linux permite leer el identificador de arranque, el control reconoce un reinicio del mismo host. Si no puede verificarlo, la recuperación tras reinicio debe hacerse con los workers detenidos y un directorio de estado nuevo.

El diseño requiere una sola máquina y un directorio privado con `flock` y renombrado atómico. Un directorio compartido entre hosts no forma parte de este diseño. Para cambiar de versión de admisión, drenar primero todos los workers que usen la versión anterior y esperar la ventana de aperturas. Las dos versiones mantienen formatos distintos y no deben operar en paralelo. No borrar el estado mientras haya workers activos.

### Entorno de validación LSAPI

La prueba ejecutada localmente usa PHP 8.2, una instancia MariaDB desechable con las 128 tablas de la exportación, sesiones sintéticas y el intermediario TCP del laboratorio. Los workers persistentes se calientan y se reemplazan por procesos nuevos usando el mismo directorio de sesiones. Este entorno verifica concurrencia, reutilización y reemplazo de procesos. El proceso administrador de LSAPI, el almacenamiento y el comportamiento del alojamiento real requieren una réplica Linux independiente.

Para repetir en esa réplica, preparar el sitio con la copia conciliada y el mismo proveedor simulado de `preparar_sitio` en `tests/mysql_capacidad_http.py`. Usar MariaDB de pruebas y una cuenta con `MAX_USER_CONNECTIONS 75`. Ejecutar el `LimitadorMySQL` del laboratorio en loopback y apuntar a él las variables `IMPE_DB_HOST` y demás credenciales exclusivamente sintéticas. Configurar el vhost de OpenLiteSpeed en loopback con LSPHP 8.2 en ProcessGroup, 30 procesos y máximo de conexiones LSAPI equivalente. Usar una serie con `LSAPI_MAX_REQS` bajo para inducir reciclaje, otra con procesos calientes y otra con diez reemplazos observados por PID. Mantener el directorio de admisión fuera del docroot con permisos 0700 y el mismo usuario de LSPHP. Verificar el SAPI, mysqlnd y los PIDs antes de medir. Reproducir las rutas de `escenario` con 10 y 30 cookies reales de la réplica y conservar las métricas HTTP y del proxy. Los límites del proveedor para la cuenta completa y la topología de Hostinger siguen requiriendo comprobación fuera de producción.

### Resultados con estadísticas activas

| Escenario | Usuarios | Login | Inicio y dos consultas de campana | Pico sesiones | Intentos físicos/s | Rechazos MySQL | HTTP 500 / 503 | p95 global |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| Frío simultáneo | 10 | 10/10 | 10/10 | 16 | 12 | 0 | 0 / 0 | 984 ms |
| Frío simultáneo | 30 | 30/30 | 30/30 | 46 | 10 | 0 | 0 / 0 | 2.484 ms |
| Caliente, sesiones válidas | 10 | — | 10/10 | 16 | 4 | 0 | 0 / 0 | 141 ms |
| Caliente, sesiones válidas | 30 | — | 30/30 | 46 | 4 | 0 | 0 / 0 | 469 ms |
| Diez reemplazos, sesiones válidas | 10 | — | 10/10 | 16 | 12 | 0 | 0 / 0 | 1.062 ms |
| Diez reemplazos, sesiones válidas | 30 | — | 30/30 | 46 | 12 | 0 | 0 / 0 | 1.140 ms |
| Diez reemplazos, login simultáneo | 10 | 10/10 | 10/10 | 16 | 12 | 0 | 0 / 0 | 953 ms |
| Diez reemplazos, login simultáneo | 30 | 30/30 | 30/30 | 46 | 11 | 0 | 0 / 0 | 1.015 ms |

El p95 de login frío de 30 usuarios fue 2.453 ms. En las pruebas de 30, los p95 de entrada y estado activo estuvieron entre 47 y 63 ms. El sondeo de transferencia llegó a 1.016 ms en frío y la preparación WebRTC a 718 ms en el escenario de login tras reciclaje. La matriz verificó dos lecturas de agenda por usuario, con aviso y horario dirigidos, y no registró lectura ni atención automática. Las pruebas deliberadas de fallo temporal sí comprobaron el 503 y la recuperación por separado.

Las pruebas de archivos comprobaron que terminar un hijo libera su candado y conserva la reserva pendiente. Se simuló el vencimiento mediante un estado expirado para comprobar que el cupo vuelve a estar disponible. Se probó un estado incompleto y el rechazo de un directorio dentro del sitio. La prueba de permisos 0755 queda condicionada a ejecutar la suite en Unix. Pasaron las simulaciones de múltiples pestañas, transferencia, recuperación y cierre SIP.

[Parámetros de procesos y reciclaje LSPHP](https://docs.litespeedtech.com/lsws/extapp/php/configuration/options/) · [Estadísticas mysqlnd](https://www.php.net/manual/en/function.mysqli-get-client-stats.php)


### Resultados sin estadísticas

| Modo | Usuarios | Login | Inicio | Primera campana | Intentos físicos/s | Rechazos MySQL | HTTP 500 / 503 |
|---|---:|---:|---:|---:|---:|---:|---:|
| Estadísticas deshabilitadas | 10 | 10/10 | 10/10 | 10/10 | 10 | 0 | 0 / 0 |
| Estadísticas deshabilitadas | 30 | 27/30 | 21/30 | 24/30 | 12 | 0 | 0 / 21 |
| Función ausente | 10 | 10/10 | 10/10 | 10/10 | 10 | 0 | 0 / 0 |
| Función ausente | 30 | 23/30 | 19/30 | 23/30 | 12 | 0 | 0 / 14 |

En estos ensayos el control conservó el margen físico a costa de disponibilidad. Los 503 de la carga se originaron al agotar el plazo local sin reembolso de cupos reutilizados, que la traza identifica como `sin_cupo`. `estado` corresponde al checkpoint y `estado_activo` a la consulta de una llamada en curso.

- Estadísticas deshabilitadas, 30 usuarios. 503 por ruta `campana` (3), `entrada` (2), `estado` (1), `estado_activo` (2), `inicio` (6), `login` (3), `transferencia` (1), `webrtc` (3).
- Función ausente, 30 usuarios. 503 por ruta `entrada` (1), `estado_activo` (1), `inicio` (4), `login` (7), `transferencia` (1).

Una prueba adicional con estadísticas activas entregó dos veces y en paralelo el mismo webhook saliente con firma sintética. Ambos PHP devolvieron 200, dos pestañas consultaron el mismo identificador de llamada y la tabla conservó una sola fila del evento. La repetición de la ráfaga fría de 30 también terminó con 30 accesos, Inicio y campanas correctos, 12 aperturas/s y cero rechazos MySQL, 500 o 503 en la carga. El publicador conserva `apto_para_publicar: false`. Falta ejecutar la matriz de LSAPI y confirmar la disponibilidad de estadísticas, las latencias y el comportamiento con el sistema de archivos de la réplica.

## Réplica FastCGI, revisión del pool y preparación de LSAPI

Hostinger reportó PHP 8.2.35 en Linux, SAPI CGI/FastCGI, mysqlnd con estadísticas activas, persistencia permitida y `mysqli.max_persistent` ilimitado. El SAPI reportado no determina el gestor de procesos ni demuestra que todos los workers compartan un mismo sistema de archivos. Se mantiene la comprobación separada de la topología y el reciclaje real.

El laboratorio añade un puente HTTP de loopback a procesos `php-cgi`, una sonda que lee contadores sin llamar a MySQL y un ejecutor para una futura réplica Linux con vhost OpenLiteSpeed. La prueba local se ejecutó con PHP 8.2.12 CGI/FastCGI en Windows, MariaDB desechable, el esquema original de 128 tablas, perfiles sintéticos y un limitador físico de 20 aperturas/s. Los reemplazos de diez workers conservaron el directorio de sesiones y el de admisión. Esto prueba el SAPI FastCGI y la coordinación local entre procesos. Linux, LSAPI, permisos POSIX y el sistema de archivos del alojamiento quedan pendientes.

### Ensayo Linux preparado

1. Usar una máquina de pruebas independiente, con PHP 8.2 CLI y OpenLiteSpeed con LSPHP 8.2. Preparar MariaDB en loopback con un datadir desechable terminado en `mysql-capacidad-<hex>/data`, una base vacía `impe_test_capacidad` y el usuario local de laboratorio `impe_capacity` con `MAX_USER_CONNECTIONS 75`. El cliente del banco accede como root sólo a esa instancia. La exportación debe contener exclusivamente `SHOW CREATE TABLE`. Usar una base vacía por ejecución.
2. Ejecutar `tests/mysql_lsapi.py` con `--php`, `--mysql`, `--port`, `--base` para la copia conciliada de producción, `--cambios` para el overlay probado, `--esquema` y `--salida`. Repetir con `--usuarios 10` y `--usuarios 30` y los perfiles `frio`, `caliente`, `reciclaje` y `reciclaje-login`. El script prepara un docroot temporal, imprime sus variables sintéticas y mantiene el proxy local abierto. Antes de enviar tráfico exige el SAPI `litespeed` y un identificador aleatorio que debe coincidir con la sonda de ese docroot.
3. Configurar un vhost exclusivamente de loopback en el puerto `--puerto-lsapi` (18082 por defecto). Ejecutar LSPHP con el mismo UID del laboratorio para que pueda acceder al docroot temporal y al directorio de admisión 0700. Usar ProcessGroup y los valores de procesos y conexiones LSAPI correspondientes a la prueba. Aplicar el `session.save_path` impreso. Para el perfil de reciclaje, conservar sesiones y estado de admisión. Confirmar los diez reemplazos comparando PIDs del grupo de pruebas. Detener el vhost antes de cerrar el banco.
4. Revisar el JSON por ruta y por segundo. Conservar las trazas de mysqlnd de la réplica y contrastarlas con los intentos físicos del proxy. Repetir con los sondeos, webhooks y transferencias simulados del banco local. La prueba de `flock`, proceso terminado y permisos POSIX se ejecuta con `php tests/mysql_admision_smoke.php` en la misma máquina Linux y como el UID de LSPHP.

[Configuración PHP de OpenLiteSpeed](https://docs.openlitespeed.org/config/php/) · [Opciones de procesos y reciclaje LSPHP](https://docs.litespeedtech.com/lsws/extapp/php/configuration/options/)

### Comprobación pasiva en Hostinger

Usar la información PHP web ya disponible para comprobar SAPI, versión, estadísticas y configuración. Eso confirma que el runtime tiene la capacidad de medición. Para verificar una reutilización real, pedir a Hostinger una captura privada desde un observador web **ya disponible** alrededor de peticiones legítimas del CRM. El observador únicamente lee `PHP_SAPI`, `PHP_VERSION`, `getmypid()`, `ini_get('mysqlnd.collect_statistics')`, `mysqli_get_client_stats()['connection_reused']` y, si existe, `mysqli_get_links_stats()`. Comparar dos muestras del mismo PID y del mismo ciclo de vida. Un incremento de `connection_reused` confirma reutilización. Un contador estable no permite concluir que haya una falla, ya que el worker puede no haber atendido una petición que reutilice su pool. Si los PIDs cambian o los contadores se reinician, descartar esa comparación.

La sonda de `tests/mysql_snapshot_web.php` implementa esta lectura en el laboratorio sin cargar la app, abrir conexiones ni ejecutar SQL. La prueba de FastCGI compara los intentos del proxy antes y después de consultar todas las sondas. El archivo es exclusivo del laboratorio. En Hostinger, sin un observador web preexistente, queda pendiente la medición del delta. La CLI por SSH usa otro SAPI y otro proceso. Activar una variable de traza, instalar un PHP público o crear conexiones para la prueba excedería la comprobación autorizada.

[Contadores de mysqlnd](https://www.php.net/manual/en/mysqlnd.stats.php) · [Estadísticas del cliente MySQLi](https://www.php.net/manual/en/function.mysqli-get-client-stats.php)

### Procedimiento condicionado a la revisión final

1. Completar la matriz LSAPI con estadísticas activas y comprobar en la réplica los mismos permisos, archivos de estado y dominio de coordinación que tendrá el despliegue. Verificar el margen de 60 sesiones y 15 aperturas/s, el 30/30 de login, Inicio y campana, cero 500/503 de admisión bajo carga normal, y la recuperación de telefonía, transferencias y webhooks. Conservar latencias por ruta y no incluir las caídas deliberadas en el conteo normal.
2. Fijar el commit y comprobar el parche con `tools/publicar_mysql_capacidad.py --check` sobre una copia representativa. Comparar las huellas instaladas antes de una ventana autorizada. Cualquier diferencia en archivos personalizados detiene el proceso. Conservar una copia externa de los archivos y el manifiesto de integridad, incluida la configuración privada relevante bajo custodia del operador.
3. En una ventana autorizada, separar la instalación del código de la activación del control. Verificar primero los recorridos básicos con la opción de admisión desactivada. Activar primero en la réplica y después en un grupo de producción que se pueda controlar y observar, sólo si el proveedor confirma el alcance de los workers y el directorio privado. La apertura de otros procesos de la cuenta debe medirse junto a la del grupo. Drenar el grupo anterior para evitar mezclas de formatos de estado y mantener el margen de toda la cuenta.
4. Detener el avance ante un rechazo de MySQL, regresiones HTTP o pérdidas de eventos. En una restauración autorizada, drenar los workers, revertir la activación y usar `--restore` con el respaldo exacto del publicador. La restauración comprueba las huellas y se detiene si detecta cambios ajenos posteriores. Repetir inicio de sesión, Inicio, campana y telefonía antes de reanudar tráfico normal.

El manifiesto conserva `apto_para_publicar: false`. Este procedimiento documenta puntos de control para una revisión posterior. Los archivos de la sonda y los ejecutores de prueba quedan fuera del paquete de publicación.

### Evaluación de mysqli.max_persistent

En frío, 30 workers conservaron 46 conexiones en caché. Dieciocho workers quedaron con una, ocho con dos y cuatro con tres. El proxy registró exactamente 46 aperturas físicas y las sondas de mysqlnd sumaron 46 conexiones exitosas y 154 reutilizaciones. Las consultas a la sonda no aumentaron los intentos del proxy.

En el mismo muestreo, `mysqli_get_links_stats()` sumó 154 en `active_plinks` con `total=0` en todos los workers. PHP registra un doble incremento de ese contador al reutilizar mediante `mysqli::real_connect()`. El código publicado de 8.2.35 conserva la ruta relevante. Esa coincidencia explica la discrepancia observada en el laboratorio y justifica verificar el binario del proveedor antes de usar un tope. No se ha medido ese contador en el proceso web real de Hostinger. Para estimar capacidad, correlacionar `connection_reused`, `cached_plinks`, las aperturas del proxy y las sesiones de MySQL.

Un tope de tres por worker no limita por sí mismo la cuenta a 75 conexiones. Con 60 workers, su cota teórica sería 180 antes de contar otros procesos. La admisión limita la tasa de apertura. La cota simultánea necesita medición del pool, procesos y usuarios MySQL de la cuenta. `mysqli.max_persistent` es una directiva de sistema y cualquier cambio debe validarse en la réplica con la compilación efectiva del proveedor.

[Reporte de doble conteo en MySQLi](https://github.com/php/php-src/issues/22631) · [Código fuente MySQLi de PHP 8.2.35](https://github.com/php/php-src/blob/php-8.2.35/ext/mysqli/mysqli_nonapi.c) · [Directivas de MySQLi](https://www.php.net/manual/en/mysqli.configuration.php)

### Resultados FastCGI con el candidato intacto

| Escenario sin tope por worker | Usuarios | Login o recarga | Inicio y campana | Pico sesiones | Aperturas/s | HTTP 500 / 503 | p95 global |
|---|---:|---:|---:|---:|---:|---:|---:|
| Frío simultáneo | 10 | 10/10 | 10/10 | 16 | 12 | 0 / 0 | 985 ms |
| Frío simultáneo | 30 | 30/30 | 30/30 | 46 | 12 | 0 / 0 | 3.047 ms |
| Caliente, sesiones válidas | 10 | 10/10 | 10/10 | 16 | 3 | 0 / 0 | 578 ms |
| Caliente, sesiones válidas | 30 | 30/30 | 30/30 | 46 | 3 | 0 / 0 | 1.687 ms |
| Diez reemplazos, sesiones válidas | 10 | 10/10 | 10/10 | 16 | 12 | 0 / 0 | 1.312 ms |
| Diez reemplazos, sesiones válidas | 30 | 30/30 | 30/30 | 46 | 12 | 0 / 0 | 1.594 ms |
| Diez reemplazos, login simultáneo | 10 | 10/10 | 10/10 | 16 | 12 | 0 / 0 | 1.172 ms |
| Diez reemplazos, login simultáneo | 30 | 30/30 | 30/30 | 46 | 12 | 0 / 0 | 984 ms |

El p95 local de entrada activa para 30 usuarios varió entre 94 y 422 ms y el de estado activo entre 63 y 296 ms. Estas mediciones incluyen el puente HTTP local. Las simulaciones verificaron múltiples pestañas, transferencias y cierre SIP, y dos entregas simultáneas del webhook firmado conservaron un solo evento. También pasaron cierre de sesión, revocación, caída deliberada de MySQL y recuperación.

En frío, los topes de 1 y 2 devolvieron 500 en Inicio para uno de diez y cuatro de treinta usuarios cada uno. El tope de 3 pasó esa ráfaga fría, con 38 sesiones para 30 usuarios. Al reasignar roles en caliente con ese mismo tope, Inicio devolvió 500 para uno de diez y cuatro de treinta. El tope de 3 tampoco se considera apto.

### Acumulación al rotar usuarios entre workers

| Usuarios | Pool tras calentamiento | Rotación 1 | Rotación 2 | Rotación 3 | Rotación 4 |
|---|---:|---:|---:|---:|---:|
| 10 | 16 | 19 | 22 | 24 | 25 |
| 30 | 46 | 58 | 69 | 75 | 75 |

Con 30 workers y sin tope, cuatro rotaciones de roles alcanzaron el límite simultáneo de 75. El máximo físico fue de 8 intentos/s y el proxy no rechazó aperturas por tasa. MariaDB sí rechazó siete aperturas independientes con código 1226 al alcanzar el límite simultáneo. Cinco fueron de Inicio, cuatro al resolver la visibilidad de Recepción de Marketing y una al preparar resultados de Ventas. Dos fueron de la sincronización de convocatorias de la campana administrativa. Las rutas HTTP devolvieron 200 mediante los recorridos parciales existentes. Por ello, un 200 y cero rechazos en el proxy no bastan como verificación de salud.

No se propone aplicar un tope por worker a Hostinger con esta evidencia. Hace falta controlar la ocupación simultánea de los pools y repetir con rotación de roles en Linux/LSAPI. El umbral de 60 sesiones se superó en la segunda rotación de 30 usuarios. La matriz LSAPI queda preparada para una réplica autorizada. La comprobación de solo lectura del paquete pasó contra la copia original, y la publicación continúa bloqueada.
