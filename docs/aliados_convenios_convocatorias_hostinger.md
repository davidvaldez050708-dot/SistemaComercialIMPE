# Integración de Aliados internacionales, Convenios y Convocatorias

Base: main `fd5fd282b3f6774d1bdc045295d655b72620ce17` (PR112 integrado). Aplicación y manifiesto: `5453b22d93cd107598e5bf25ff9656fe08f3a311`.

## Causas y correcciones

PR112 sólo consultaba configuraciones de formularios cuyo `seguimiento_id` era nulo. No había alta ni edición, la ubicación era texto único y un aliado desaparecía al vincular su institución. El creador nacional exigía estados mexicanos y el enlace/QR de su modal era sólo una previsualización, sin relación persistente internacional. La depuración nacional podía eliminar convocatorias antiguas.

- Se amplía la configuración original `formulario_aliados` con país, región/provincia y ciudad, contacto y autor. Se conserva ID, token, programas y procedencias al editar. Handly mantiene sus programas y enlace anteriores. Se completa únicamente su ubicación conocida cuando no ha sido configurada.
- El alta mexicana conserva el flujo territorial original. Elegir México en la configuración internacional ofrece acceso a ese flujo; el backend impide registrar una institución mexicana sin catálogos territoriales. Otros países no necesitan estado_id ni municipio_id mexicanos. Se ofrece Otro país con nombre libre para países fuera de la lista.
- Convenios utiliza `seguimientos_vinculacion_post_envio` y `marketing_convenio_convocatorias`, sin crear un segundo catálogo de convenios. Al registrar el primer convenio se vincula una única institución canónica mediante el `seguimiento_id` original; los siguientes guardados reutilizan ese ID. No se inventan datos verificados, reuniones, estados ni analistas. La clave `ALIADO_FORMULARIO:id` y el bloqueo de configuración evitan crear otro expediente al reenviar. No se generan ni envían documentos legales automáticamente ni se falsean etapas previas del expediente.
- Se extraen el modal Crear convocatoria, su modal QR y su JS a componentes compartidos. Nacionales e internacionales incluyen esos mismos archivos. La mayor parte del diff de la vista nacional es traslado de código, no otra implementación del modal.
- Se guarda cada relación en `formulario_aliado_convocatorias`, con oferta autorizada, formulario, referencia aleatoria y convenio original cuando existe. El enlace definitivo se guarda en `convocatorias.enlace_registro`; no se guarda otra copia en la nueva relación ni un archivo temporal de QR. La previsualización y la URL definitiva usan la misma referencia.
- La vigencia internacional utiliza la fecha configurada del CRM en PHP, igual que el creador, aunque MySQL esté en otra zona horaria. Se prueba con offsets +12:00 y -12:00.
- Las ligas públicas validan referencia, aliado, programa, formulario, estado y vigencia. Las respuestas conservan su convocatoria en `formulario_aliado_procedencias.convocatoria_id`. No se puede cambiar de programa por POST ni usar una liga de Registro como Inscripción. Los enlaces generales y los anteriores de aliado sin referencia siguen funcionando.
- Se reutiliza QuickChart, el proveedor actual. Consultar/descargar genera PNG desde el enlace persistido validado; no se guarda un bitmap redundante. Las personalizaciones de QR del modal siguen siendo opciones de previsualización/descarga, como en el mecanismo nacional: no se añade almacenamiento nuevo de estilos de QR.
- La depuración nacional omite convocatorias internacionales vinculadas. La edición nacional no permite sobrescribir sus relaciones ni sustituir sus ligas por URLs arbitrarias.
- Bachillerato General reutiliza exactamente `bachillerato|bachillerato-286`, confirmado por el usuario. No existe un ID numérico 286 en el catálogo de esta implementación: es la clasificación existente. No se crea otro programa.

## Permisos

Se respeta PermissionHelper y el bloqueo de operaciones del Administrador. La migración añade un permiso de configuración administrativa, `aliados.internacionales.configurar`, y lo asigna únicamente a Administración. No habilita Convenios o Marketing para ese rol. Tras migrar, cerrar sesión e iniciarla de nuevo para actualizar permisos.

Marketing con `convocatorias.ver` y `convocatorias.crear` puede consultar aliados internacionales y crear convocatorias, sin requerir `aliados.ver` ni territorios nacionales, como en su flujo nacional. Para registrar un convenio internacional, el operador necesita `aliados.ver`, `aliados.internacionales.configurar` y el permiso operativo existente `convenios.gestionar`. Asignar de forma explícita el permiso de configuración a la cuenta o rol autorizado usando la administración de permisos existente. No se cambian permisos masivamente ni usuarios en producción. Las respuestas personales de formularios continúan reservadas a Administración. POST de configuración, convenio y convocatoria llevan CSRF.

## Pruebas

- PHP/JS: sintaxis y reutilización del componente.
- MariaDB local aislada: alta US, edición, país/región/ciudad; doble alta rechazada; convenio reutiliza ID; institución sin territorio/analista ficticio; dos convocatorias; asociación atómica; procedencia; referencias y ofertas ajenas rechazadas; rollback completo; creación nacional con estado; depuración conserva historia internacional.
- Playwright/Edge: formularios y controladores reales; alta, edición, convenio; Marketing usa el mismo modal; URL definitiva igual a la previsualización; dos convocatorias tras recargar; CSRF, permisos y referencias consumidas; escritorio/móvil sin overflow; el modal nacional mantiene estados. QR reales solicitados al proveedor, decodificados con jsQR y comparados con la liga exacta; descarga PNG con Content-Disposition attachment.
- CI añadido: MySQL8 y MariaDB10.11, Chromium, QR real y réplicas del publicador.

## Migración incremental

Archivo CLI: `tools/migrar_aliados_convocatorias.php`, usando el SQL nuevo y las migraciones compartidas existentes. `--check` no escribe; `--apply` es explícito. Sólo tablas InnoDB. Añade columnas y una tabla de relación; permite NULL en `seguimientos_vinculacion.estado_id` y `analista_id`, conservando tipos y todos los valores existentes. Esta relajación permite instituciones internacionales sin atribuirlas a México o a un analista inexistente. No altera IDs ni borra información. DDL de MySQL confirma implícitamente: respaldar antes. No hay migración destructiva inversa: conservar tablas y respuestas nuevas al revertir archivos.

## Hostinger: publicación manual

No se ha ejecutado nada en producción. No usar git pull en public_html. El publicador instala 22 archivos PHP/JS/CSS revisados, con parches por contexto para PHP y huellas estrictas para JS. Conserva personalizaciones fuera de los bloques tocados. Si se personalizó el modal nacional dentro de un bloque que ahora se extrae, se detiene para revisar y trasladar esa variante al componente compartido; nunca forzar el reemplazo. SQL y preparadores permanecen fuera del sitio. No necesita Python en Hostinger.

1. Descargar `tools/publicar_aliados_convocatorias_hostinger.sh` fijado al commit de entrega de este PR y guardarlo como `$HOME/publicar-aliados-convocatorias.sh`. Revisar:

```bash
bash "$HOME/publicar-aliados-convocatorias.sh" --check
```

2. Respaldar la BD con hPanel o la herramienta de respaldo institucional y verificar la copia, fuera de public_html. No pasar al siguiente paso con conflictos de archivos pendientes.

3. Extraer dependencias del preparador en una carpeta privada NUEVA desde la copia HTTPS que dejó el publicador:

```bash
VERSION=5453b22d93cd107598e5bf25ff9656fe08f3a311
PREPARACION="$HOME/aliados-convocatorias-$VERSION"
mkdir -m 700 "$PREPARACION" &&
git -C "$HOME/crm-publicaciones/aliados-convocatorias-actual" archive "$VERSION" \
 tools/migrar_aliados_convocatorias.php \
 database/migrations/2026_10_20_aliados_convocatorias.sql \
 database/migrations/2026_09_04_flujo_post_envio_vinculacion.sql \
 database/migrations/2026_10_16_estado_convenio_convocatoria.sql | tar -x -C "$PREPARACION"
```

4. Con el respaldo verificado, ejecutar explícitamente la migración, separada del publicador. Usa la configuración privada existente, no copia credenciales:

```bash
IMPE_RUNTIME_ROOT="$HOME/domains/fundacionrededumex.org/public_html" \
php "$PREPARACION/tools/migrar_aliados_convocatorias.php" --apply
IMPE_RUNTIME_ROOT="$HOME/domains/fundacionrededumex.org/public_html" \
php "$PREPARACION/tools/migrar_aliados_convocatorias.php" --check
```

5. Instalar los archivos:

```bash
bash "$HOME/publicar-aliados-convocatorias.sh" --apply
```

El publicador guarda respaldo externo, rechaza cambios concurrentes y restaura archivos alcanzados si falla. Renovar sesión para el permiso de configuración. Revisar Handly, guardar/editar un aliado de prueba autorizado, registrar su convenio con el operador permitido, abrir el modal desde la institución, seleccionar 286, crear dos convocatorias, probar ligas y QR después de recargar. Verificar una convocatoria nacional y ambos formularios generales.

6. Para revertir archivos usar exactamente la ruta de respaldo impresa:

```bash
bash "$HOME/publicar-aliados-convocatorias.sh" --restore "$HOME/respaldos-crm/aliados-convocatorias-FECHA-PID"
```

No borra migración, instituciones, convenios ni respuestas. Rechaza restaurar sobre archivos que se editaron después de instalar. Si se revierte todo el código nuevo, dejar de distribuir sus nuevas ligas hasta reinstalarlo: el código previo desconoce las referencias de convocatoria. No restaurar toda la BD para corregir un problema de presentación.


## Corrección adicional de interfaz y acceso

La revisión de aplicación `5453b22d93cd107598e5bf25ff9656fe08f3a311` incorpora el directorio compacto y la navegación internacional de Marketing/Cuenta Clave. Detalle, matriz de permisos y pruebas: [Diseño y acceso internacional](aliados_internacionales_diseno_acceso.md). No concede permisos ni territorios; editar/gestionar/crear conserva las comprobaciones de cada operación. Este rediseño no añade migraciones; si ya se aplicó la migración de PR114, no hay que repetirla para la interfaz.

Publicador actualizado (descargar siempre una revisión fija y ejecutar primero `--check`):

```bash
curl -fsSL --retry 2 'https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/DELIVERY_SHA/tools/publicar_aliados_convocatorias_hostinger.sh' -o "$HOME/publicar-aliados-convocatorias.sh"
bash "$HOME/publicar-aliados-convocatorias.sh" --check
# Solo después de una revisión compatible y del respaldo de BD si falta la migración original:
bash "$HOME/publicar-aliados-convocatorias.sh" --apply
# Restaurar archivos con la ruta exacta que imprimió --apply:
bash "$HOME/publicar-aliados-convocatorias.sh" --restore "$HOME/respaldos-crm/aliados-convocatorias-FECHA-PID"
```

Sustituye `DELIVERY_SHA` por el commit de entrega indicado en el PR, no por `main`. La entrega contiene variantes revisadas de main/PR112 y de PR114 anterior (`aa1f9faf`). Una personalización desconocida puede producir un conflicto: conservar el sitio y revisar el archivo, sin forzar el reemplazo. El respaldo se guarda fuera de `public_html`; la restauración rechaza archivos que hayan sido modificados después de instalar. El publicador no ejecuta SQL ni requiere Python o Node en Hostinger. La migración original, si sigue pendiente, es un paso manual separado y requiere respaldo de BD; `--restore` restaura archivos, no revierte DDL.
