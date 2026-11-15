# R16 · Integridad y restaurabilidad

La base de este cambio es 507c236 de codex/respaldo-base-datos. El módulo anterior tenía generación, descarga, historial y confirmación visual. Comprobaba la existencia del SQL y aceptaba cargas directas. El Director podía ejecutar las mismas operaciones que el Administrador. La rotación eliminaba respaldos.

- El servicio genera la exportación en un directorio privado ya creado. Requiere InnoDB para garantizar una instantánea de datos y usa single-transaction, rutinas, eventos y triggers. Durante la exportación se necesita evitar cambios DDL concurrentes. Un error del cliente, stderr no vacío o ausencia del marcador final impiden publicar el resultado.
- El manifiesto firmado registra el nombre, tamaño, fecha, esquema y SHA-256 del archivo. Se publica al final mediante rename. La clave HMAC es independiente del directorio y debe quedar accesible sólo al proceso autorizado. El historial distingue archivos antiguos y errores de verificación.
- El controlador pide CSRF incluso al descargar y exige rol Administrador y confirmación explícita al restaurar. El Director conserva generación y consulta. Los históricos se conservan. Se retiran las acciones de borrado y de restauración de SQL subido.
- La restauración web operativa está bloqueada hasta disponer de una infraestructura de validación, mantenimiento y recuperación verificada. La prueba CLI importa un respaldo autenticado en un esquema vacío de una instancia desechable.

## Verificación previa a una publicación

Ejecutar desde una carpeta privada con Python 3.

```sh
python3 comprobar_respaldo_r16.py --site "$HOME/domains/fundacionrededumex.org/public_html" --check
```

Cotejar también las rutas del módulo, el permiso del menú y la protección HTTP de los archivos históricos. El CHECK compara los tres archivos de este cambio con el origen revisado. Una diferencia exige conciliar la versión instalada. Antes de aplicar, confirmar el soporte de proc_open, las herramientas dump y cliente, los privilegios de exportación de todos los objetos, el espacio y los límites del proceso web. Configurar IMPE_BACKUP_DIR fuera de public_html con permisos 0700 y una clave aleatoria de 32 bytes representada en hexadecimal en IMPE_BACKUP_HMAC_KEY. Mantenerla fuera del directorio de respaldos.

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
