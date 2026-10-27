# Cartera territorial compartida de seguimientos

## Regla operativa

Los seguimientos activos se trabajan según el territorio vigente, no según quien los importó. Todos los analistas activos asignados al mismo estado trabajan los mismos expedientes. Se validan fecha inicial/final, estado activo del usuario, rol y territorio. No se copian instituciones ni se actualiza masivamente `analista_id`.

En Guanajuato, los 214 registros originales de Diego Bahena permanecen físicamente iguales. Alan Jafede y Denisse Cortés acceden a los mismos 214; las modificaciones de uno son visibles para el otro. Diego pierde el acceso operativo a Guanajuato al perder su asignación, pero conserva la autoría de las llamadas y gestiones que realizó. Si cambia nuevamente el equipo, la cartera y sus pendientes siguen a la siguiente asignación vigente sin ejecutar otro traspaso SQL.

Cuenta Clave consulta por su vínculo vigente con el equipo del territorio. Sus listas y resúmenes cuentan expedientes distintos, evitando multiplicar el total por el número de analistas. Administración y Dirección conservan sus alcances nacionales de lectura. El alcance territorial no concede permisos: cada acción sigue requiriendo su permiso específico.

## Cobertura de la corrección

- Lista, filtros de responsable, detalle, formulario de trabajo, ruta operativa y expediente.
- Inicio, atención pendiente, agenda y recordatorios; reuniones heredadas y reprogramación.
- Observaciones compartidas del expediente: ambos reciben el pendiente; la lectura por uno atiende el aviso compartido. Se conserva el autor y destinatario de las observaciones antiguas. Las nuevas se dirigen al equipo vigente.
- Borradores, oficios, programación y correos. Los nuevos correos usan la identidad del operador actual; no se reescriben envíos, documentos ni grabaciones anteriores.
- Reportes de cartera y actividad, conservando el filtro del actor real para las métricas. El ranking de Desempeño mantiene las llamadas en el usuario que realmente las hizo.
- Búsqueda institucional desde Recepción, contexto de transferencia y vínculo al expediente al recibir/devolver llamadas. Cada destino se valida por asignación actual; los controles propios de las llamadas y audios se mantienen.

`SeguimientoTerritorialScope` centraliza las condiciones SQL. Las escrituras no usan el contexto ampliado de lectura de una ruta. Los parámetros opcionales de sólo lectura están limitados a los getters invocados durante esa consulta; la consulta nacional requiere sesión y permiso de ver seguimientos. El helper reutiliza la conexión habitual del CRM y no mantiene una caché de asignaciones entre consultas.

El campo histórico `analista_id` sigue presente para preservar procedencia. Consultarlo directamente no representa la cartera operativa actual. No se deben sumar las carteras individuales compartidas para obtener el total del equipo.

## Instalación selectiva

Herramientas: `tools/publicar_cartera_territorial.py`, `tools/cartera_territorial_release.json` y `tools/verificar_cartera_territorial.php`.

El manifiesto contiene exclusivamente las versiones de Hostinger comprobadas con el usuario y su resultado corregido. El publicador comprueba todas las versiones y dependencias antes de escribir, valida sintaxis PHP, respalda fuera del directorio público, aplica archivos de forma atómica y permite restaurar. Rechaza variantes desconocidas y una restauración si existen modificaciones posteriores. Admite LF y CRLF. La política nacional, PermissionHelper y DesempenoModel se verifican como dependencias, sin sustituirlos.

No ejecuta migraciones ni modifica usuarios, asignaciones, seguimientos o datos de telefonía. No accede a Zadarma. No debe desplegarse la rama completa encima de Hostinger; usar el publicador selectivo para conservar el resto de sus personalizaciones.

```bash
python3 publicar_cartera_territorial.py --site "$SITIO" --backup-root "$RESPALDOS" --check
python3 publicar_cartera_territorial.py --site "$SITIO" --backup-root "$RESPALDOS" --apply
php verificar_cartera_territorial.php --site "$SITIO" --estado 11
```

El último comando abre una transacción de sólo lectura y compara el conjunto de IDs accesibles de cada analista vigente contra los expedientes únicos activos del estado. Muestra únicamente IDs y conteos, sin teléfonos, contactos ni audios. Los conteos pueden crecer legítimamente si ya se agregaron instituciones después del diagnóstico inicial.

Para restaurar, usar la ruta privada `RESPALDO` que imprime el publicador:

```bash
python3 publicar_cartera_territorial.py --site "$SITIO" --backup-root "$RESPALDOS" --restore "$RESPALDO"
```

## Validación

`tests/cartera_territorial_mysql.py` copia el código a una carpeta efímera, sustituye únicamente su configuración por una base local de prueba y bloquea proveedores de correo. Usa una base temporal exclusiva en MariaDB local (127.0.0.1:33319) y la elimina al terminar. Nunca carga la configuración ni los datos reales del CRM. Del volcado versionado sólo utiliza estructura, no los INSERT con datos.

Prueba 214 expedientes con autor anterior, dos asignaciones vigentes bajo la misma Cuenta Clave, territorios ajenos, asignaciones futuras, usuarios y roles inactivos. Verifica listados, resumen, Inicio y ajustes posteriores, permisos de formularios, oficios, identidad de correo, ruta inicial y avanzada, agenda heredada, recordatorios, observaciones compartidas, reportes, revalidación de acceso y rechazo de nuevas gestiones del usuario anterior. Compara la llamada original antes/después y comprueba la autoría del ranking, sin invocar servicios de telefonía o enviar correos. También ejecuta el comprobador CLI real en una transacción READ ONLY.

`tests/publicacion_cartera_territorial_test.py` verifica los 44 archivos del paquete, dependencias y variantes, aplicación idempotente, respaldo, restauración exacta y ausencia de escrituras durante --check, con LF y CRLF. Una grabación de control permanece intacta.

```bash
PHP_BINARY=php python3 tests/cartera_territorial_mysql.py
python3 tests/publicacion_cartera_territorial_test.py
```

Las pruebas automatizadas no sustituyen la comprobación final en las sesiones reales de Hostinger después de instalar. No se ha ejecutado ninguna transferencia o modificación de datos productivos desde este entorno.

## Compatibilidad de PermissionHelper (entrega 1.1)

La dependencia admite dos versiones exactas verificadas: la de supervisión nacional de Dirección (`0d4441b0…`) y la corrección posterior de Convenios (`1ef40e3d…`, commit `3ec9021b`). Esta última permite publicar por permisos y mantiene territoriales a Asesor, Analista y Cuenta Clave. Sus controles de `tienePermiso` permanecen iguales y son compatibles con la cartera. El publicador verifica ambas variantes mediante SHA256 y conserva la instalada sin escribir ese archivo. Cualquier tercera variante sigue bloqueando la instalación. No cambia el contenido de los 44 archivos que se instalarán.
