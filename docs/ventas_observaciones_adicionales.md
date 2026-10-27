# Observaciones adicionales de llamadas de Ventas

Desde **Mis llamadas → Consultar resultado**, el asesor puede añadir una observación a un resultado ya guardado. El resultado, la nota inicial, el nombre/contacto, la programación y las grabaciones conservan sus valores. No se añadieron acciones para editar o eliminar notas ni para corregir el resultado.

Cada anexo admite hasta 1000 caracteres y conserva el autor obtenido de la cuenta autenticada y la fecha de creación. La fecha se guarda en UTC y se presenta en `America/Mexico_City`. El texto se muestra literalmente, sin interpretar HTML.

La consulta en **Supervisión de llamadas → Consultar resultado** incluye estos anexos: Administración y Dirección conservan su alcance global; Cuenta Clave sólo consulta a su equipo vigente. No se exige permiso de audio para leer una observación. El asesor sólo añade notas a resultados propios y con una relación exacta entre usuario, referencia PBX y extensión, aunque su extensión actual haya cambiado.

## Integridad

- Tabla nueva: `telefonia_ventas_observaciones`. No se añaden columnas a las tablas existentes.
- Cada nota conserva también la identidad PBX y extensión del resultado para impedir cruces si se reutilizan identificadores numéricos en una importación.
- El servicio sólo inserta anexos. No actualiza resultados ni dispara conciliaciones, tareas, agenda, llamadas o audio. Las métricas siguen usando exclusivamente los datos originales.
- Token de envío único por autor; repetir el mismo envío devuelve la misma nota. Usar el token con otro contenido o identidad se rechaza.
- Autorización vigente en cada petición; POST con CSRF para añadir. No hay rutas de modificación o borrado.
- Un envío fallido conserva su texto y token en memoria de la página para reintentar. Un F5 no conserva un borrador sin guardar. Si se perdió la confirmación, consultar la lista o reintentar sin modificar la nota evita duplicados.

## Instalación selectiva

Se entregan un publicador privado, su manifiesto y un instalador de tabla. El manifiesto admite exactamente las cuatro versiones verificadas por hashes de Hostinger y dos archivos nuevos. También comprueba, sin modificarlas, las versiones de `TelefoniaVentasLlamadasService.php` y `SupervisionLlamadasRepository.php`.

Descargar los cuatro componentes desde la revisión fija indicada en el mensaje de instalación y verificar sus SHA-256. Mantenerlos fuera de `public_html`. La migración debe llamarse `ventas_observaciones.sql` junto a `instalar_observaciones_ventas.php`.

Orden de ejecución, en la carpeta privada del paquete:

```bash
SITIO="$HOME/domains/fundacionrededumex.org/public_html"
RESPALDOS="$HOME/respaldos-impe-ventas-observaciones"
python3 publicar_ventas_observaciones.py --site "$SITIO" --backup-root "$RESPALDOS" --check
php instalar_observaciones_ventas.php --site "$SITIO" --check
php instalar_observaciones_ventas.php --site "$SITIO" --apply
python3 publicar_ventas_observaciones.py --site "$SITIO" --backup-root "$RESPALDOS" --apply
```

Las comprobaciones no escriben. La migración ejecuta una única sentencia `CREATE TABLE IF NOT EXISTS`, verifica estructura e idempotencia y no modifica los datos anteriores. El publicador valida todos los archivos antes de la primera escritura, respalda las versiones exactas y revierte sus escrituras si ocurre un error. No instala versiones desconocidas.

Para restaurar los archivos, usar el mismo paquete y la carpeta exacta `RESPALDO` que imprimió su aplicación:

```bash
python3 publicar_ventas_observaciones.py --site "$SITIO" --backup-root "$RESPALDOS" --restore RUTA_DEL_RESPALDO
```

La restauración conserva la tabla y todas las notas guardadas; no existe una migración de borrado. Recargar el CRM después de instalar/restaurar.

## Pruebas

- `ventas_observaciones_http_test.py`: servicio y controlador reales con MariaDB aislado; propietario exacto, supervisión/equipo, permisos revocados, sesión/CSRF, Unicode, reintentos y comparación completa de los datos originales. Incluye creación/verificación/reinstalación de la tabla y rechazo de migración o estructura desconocida.
- `ventas_observaciones_browser.cjs`: vistas y scripts reales, 1366/390 px, campos originales bloqueados, adición literal sin HTML, pérdida de confirmación, reintento, cierre/reapertura durante un envío y captura inicial con el formulario original.
- `supervision_llamadas_browser.cjs`: consulta segura de anexos, detalle y dos grabaciones, filtros/sidebar, tamaños 1366/768/390/320 px.
- `publicacion_ventas_observaciones_test.py`: archivos nuevos y existentes, LF/CRLF, respaldo privado, versión desconocida, dependencia alterada, idempotencia y restauración.

Límite de la validación histórica: `telefonia_ventas_operacion_browser.cjs` agota su espera del pendiente después de recargar el shell (línea 72). Se reprodujo también con el script de resultados de la revisión anterior `3174965d`, sin el nuevo evento de anexos. No se modificó el motor ni se presenta esa prueba como aprobada por esta entrega.

Verificación manual después de instalar:

1. Abrir una llamada con resultado registrado y revisar que resultado y observación inicial sigan bloqueados.
2. Añadir una nota y volver a abrir el resultado; comprobar texto, autor y fecha. No hay acciones de edición/borrado.
3. Consultar la misma llamada desde Supervisión y comprobar su nota.
4. Comprobar que las métricas y los botones de grabación conserven su comportamiento.
