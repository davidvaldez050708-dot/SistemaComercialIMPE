# R16 · Integridad y restaurabilidad

La base de este cambio es 507c236 de codex/respaldo-base-datos. El módulo anterior tenía generación, descarga, historial y confirmación visual. Comprobaba la existencia del SQL y aceptaba cargas directas. El Director podía ejecutar las mismas operaciones que el Administrador. La rotación eliminaba respaldos.

- El servicio genera la exportación en un directorio privado ya creado. Requiere InnoDB para garantizar una instantánea de datos y usa single-transaction, rutinas, eventos y triggers. Durante la exportación se necesita evitar cambios DDL concurrentes. Un error del cliente, stderr no vacío o ausencia del marcador final impiden publicar el resultado.
- El manifiesto firmado registra el nombre, tamaño, fecha, esquema y SHA-256 del archivo. Se publica al final mediante rename. La clave HMAC es independiente del directorio y debe quedar accesible sólo al proceso autorizado. El historial distingue archivos antiguos y errores de verificación.
- El controlador pide CSRF incluso al descargar y exige rol Administrador y confirmación explícita al restaurar. El Director conserva generación y consulta. Los históricos se conservan. Se retiran las acciones de borrado y de restauración de SQL subido.
- La restauración está habilitada exclusivamente en una instalación aislada configurada con IMPE_BACKUP_LAB=YES, DB_NAME=impe_r16_fuente y una instancia desechable en loopback con puerto explícito. Mantener el planificador de eventos apagado. La restauración operativa en Hostinger continúa bloqueada.

## Verificación previa a una publicación

Ejecutar desde una carpeta privada con Python 3.

```sh
python3 comprobar_respaldo_r16.py --site "$HOME/domains/fundacionrededumex.org/public_html" --check
```

Cotejar también las rutas del módulo, el permiso del menú y la protección HTTP de los archivos históricos. El CHECK de esta entrega compara los tres archivos con la edición c640b946, ya preparada para Hostinger. Una diferencia exige conciliar la versión instalada. Antes de aplicar, confirmar el soporte de proc_open, las herramientas dump y cliente, los privilegios de exportación de todos los objetos, el espacio y los límites del proceso web. Configurar IMPE_BACKUP_DIR fuera de public_html con permisos 0700 y una clave aleatoria de 32 bytes representada en hexadecimal en IMPE_BACKUP_HMAC_KEY. Mantenerla fuera del directorio de respaldos.

Desde una copia privada de la entrega, el publicador verifica las huellas exactas de los originales y del paquete, y ejecuta el lint PHP. Realiza primero el CHECK y concilia cualquier diferencia. La aplicación y la reversión deben ejecutarse en una ventana de mantenimiento para evitar que peticiones simultáneas lean versiones mezcladas de los archivos. El directorio de respaldo debe ser nuevo para APPLY y conservarse para ROLLBACK. El código no requiere una migración.

```sh
python3 -B "$ENTREGA/tools/publicar_respaldo_r16.py" --site "$SITIO" --stage "$ENTREGA" --check
python3 -B "$ENTREGA/tools/publicar_respaldo_r16.py" --site "$SITIO" --stage "$ENTREGA" --backup "$COPIA" --apply
python3 -B "$ENTREGA/tools/publicar_respaldo_r16.py" --site "$SITIO" --stage "$ENTREGA" --backup "$COPIA" --rollback
```

APPLY y ROLLBACK se probaron fuera de Hostinger sobre los bytes originales de Git. También se comprobó que una personalización bloquea CHECK y APPLY.

## Prueba aislada

Usar una instancia MariaDB desechable en loopback con credenciales sintéticas, una configuración local que defina DB_HOST con puerto, DB_USER, DB_PASSWORD y DB_NAME=impe_r16_fuente, y un directorio privado vacío. El fixture crea los dos esquemas y verifica la importación y los registros esperados. Ejecutar con IMPE_BACKUP_LAB=YES y la configuración anterior.

```sh
php tests/respaldo_r16_db.php /ruta/a/copia-aislada-del-proyecto
```

La prueba en MariaDB 10.4.32 conserva una copia previa generada y verificada. Inyecta un SQL autenticado de prueba que crea una tabla y falla en la siguiente sentencia. Comprueba que la fuente queda intacta y recupera el estado previo en un esquema limpio distinto. MySQL y MariaDB pueden confirmar DDL de forma implícita. Para habilitar la restauración web falta validar el aislamiento operativo, el cambio controlado de destino y su reversión en infraestructura equivalente a la del alojamiento.

### Configuración privada en Hostinger

El servicio busca `impe-private/respaldo.local.php` junto a `public_html`, con permisos 0600 y una ruta canónica. El archivo devuelve un arreglo con `directorio` (ruta absoluta privada, permisos 0700), `clave_hmac` (32 bytes aleatorios en hexadecimal) y `directorio_herramientas` (en el servidor inspeccionado, `/usr/bin`). Las variables de entorno explícitas tienen prioridad. Proteger también el directorio de configuración y conservar la misma clave para verificar los respaldos ya firmados. Las herramientas, los permisos y el acceso en PHP web se deben comprobar antes de habilitar el flujo en producción.


## Restauración aislada desde el módulo

- Usar una copia desechable sin trabajos de fondo ni integraciones externas y con acceso al módulo limitado a un administrador de prueba. El servidor de MariaDB debe ser exclusivo del laboratorio. Mantener el esquema fuente hasta concluir la revisión.
- El administrador elige un respaldo firmado del historial y confirma. El servicio valida el archivo antes de iniciar procesos de importación y obtiene un bloqueo exclusivo.
- El servicio conserva una copia SQL privada del esquema aislado previamente activo o de la fuente del laboratorio en la primera ejecución. Protege su huella con HMAC y comprueba que se pueda importar en un esquema de verificación. Después importa el candidato en otro esquema nuevo y comprueba que tenga tablas.
- Al completar las comprobaciones, el archivo privado `restauracion-activa` identifica el esquema que contiene los datos restaurados. Este archivo sirve para seleccionar el resultado en el laboratorio y el esquema que respaldará la siguiente restauración. El CRM conserva su conexión original. Para verificar el resultado abrir el esquema indicado mediante un cliente de solo lectura y comprobar las entidades de la prueba.
- Si la importación falla, el puntero y la versión anterior quedan intactos. Se intenta descartar los esquemas parciales; una limpieza fallida se registra para operación manual. No se necesita deshacer DDL sobre el esquema activo. Conservar las copias previas y versiones hasta completar las comprobaciones.
- El fixture también invoca el procesamiento del controlador con Administrador y Director, con y sin CSRF válido y confirmación. Para reproducirlo copiar al laboratorio el controlador y los dos helpers de permisos del repositorio, además del servicio y la configuración sintética.
