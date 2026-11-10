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
